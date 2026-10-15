<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The File Cabinet: folders, documents and the visibility rules between them.
 *
 * Two things matter here. First, nothing is ever served straight off disk: document bytes go
 * through Friendica's storage backend and are read back only by a route that re-checks
 * permissions, so a guessed URL cannot leak board-only minutes. Second, documents are
 * versioned rather than overwritten, because "which budget is current?" is a real question
 * at an annual meeting.
 *
 * Note that as of 2026.05 an addon cannot ship Smarty templates (the template search path is
 * theme -> parent theme -> view/), so this addon renders its own markup. That is also what
 * keeps it independent of whichever theme an organization chooses.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Core\Storage\Capability\ICanReadFromStorage;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Util\DateTimeFormat;

final class VaultRepository
{
	public const VIS_PUBLIC  = 'public';
	public const VIS_MEMBERS = 'members';
	public const VIS_BOARD   = 'board';
	public const VIS_ADMIN   = 'admin';
	public const VIS_INHERIT = 'inherit';

	/** Highest upload size accepted from an officer, in bytes. */
	public const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

	// ---------------------------------------------------------------- folders

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function folders(): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(OrgSchema::TABLE_FOLDER, [], [], ['order' => ['sort_order', 'name']]);
	}

	public static function folderById(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$folder = DBA::selectFirst(OrgSchema::TABLE_FOLDER, [], ['id' => $id]);

		return DBA::isResult($folder) ? $folder : null;
	}

	/**
	 * Creates a folder. Returns the new id, or 0 on failure.
	 */
	public static function createFolder(string $name, string $description, string $visibility, int $sortOrder = 0, int $createdBy = 0): int
	{
		$name = trim($name);

		if ($name === '' || !OrgSchema::ready()) {
			return 0;
		}

		$slug = self::uniqueSlug($name);

		$inserted = DBA::insert(OrgSchema::TABLE_FOLDER, [
			'slug'        => $slug,
			'name'        => $name,
			'description' => $description !== '' ? $description : null,
			'visibility'  => self::sanitizeVisibility($visibility),
			'sort_order'  => $sortOrder,
			'created'     => DateTimeFormat::utcNow(),
			'created_by'  => $createdBy,
		]);

		return $inserted ? DBA::lastInsertId() : 0;
	}

	public static function updateFolder(int $id, array $fields): bool
	{
		if (!OrgSchema::ready()) {
			return false;
		}

		if (array_key_exists('visibility', $fields)) {
			$fields['visibility'] = self::sanitizeVisibility((string) $fields['visibility']);
		}

		if (array_key_exists('name', $fields)) {
			$fields['name'] = trim((string) $fields['name']);
		}

		return DBA::update(OrgSchema::TABLE_FOLDER, $fields, ['id' => $id]);
	}

	/**
	 * Deletes a folder only when it is empty, so documents cannot silently disappear.
	 */
	public static function deleteFolder(int $id): bool
	{
		if (!OrgSchema::ready() || $id <= 0) {
			return false;
		}

		if (DBA::count(OrgSchema::TABLE_DOCUMENT, ['folder_id' => $id]) > 0) {
			return false;
		}

		return DBA::delete(OrgSchema::TABLE_FOLDER, ['id' => $id]);
	}

	// -------------------------------------------------------------- documents

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function documentsInFolder(int $folderId): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_DOCUMENT,
			[],
			['folder_id' => $folderId, 'is_current' => true],
			['order' => ['title']]
		);
	}

	public static function documentById(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$document = DBA::selectFirst(OrgSchema::TABLE_DOCUMENT, [], ['id' => $id]);

		return DBA::isResult($document) ? $document : null;
	}

	/**
	 * Every revision of a document, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function revisions(int $documentId): array
	{
		$document = self::documentById($documentId);

		if ($document === null) {
			return [];
		}

		// Walk back up the supersedes chain to the root of this document's history.
		$rootId = $documentId;
		$guard  = 0;

		while ($document['supersedes'] > 0 && $guard++ < 100) {
			$rootId   = (int) $document['supersedes'];
			$document = self::documentById($rootId);

			if ($document === null) {
				break;
			}
		}

		// Then collect forwards from the root.
		$chain = [];
		$cursor = $rootId;
		$guard  = 0;

		while ($cursor > 0 && $guard++ < 100) {
			$row = self::documentById($cursor);

			if ($row === null) {
				break;
			}

			$chain[] = $row;

			$next = DBA::selectFirst(OrgSchema::TABLE_DOCUMENT, ['id'], ['supersedes' => $cursor], ['order' => ['id' => 'DESC']]);
			$cursor = DBA::isResult($next) ? (int) $next['id'] : 0;
		}

		return array_reverse($chain);
	}

	/**
	 * Stores uploaded bytes and creates the document row.
	 *
	 * @return int the new document id, or 0 on failure
	 */
	public static function storeDocument(int $folderId, string $title, string $description, string $filename, string $filetype, string $data, string $visibility, int $uploadedBy, int $supersedes = 0): int
	{
		if (!OrgSchema::ready() || self::folderById($folderId) === null) {
			return 0;
		}

		if ($data === '' || strlen($data) > self::MAX_UPLOAD_BYTES) {
			return 0;
		}

		$reference = self::store($data);

		if ($reference === '') {
			return 0;
		}

		$revision = 1;

		if ($supersedes > 0) {
			$previous = self::documentById($supersedes);
			$revision = $previous !== null ? (int) $previous['revision'] + 1 : 1;

			// The superseded revision stays for audit, but stops being the current one.
			DBA::update(OrgSchema::TABLE_DOCUMENT, ['is_current' => false], ['id' => $supersedes]);
		}

		$inserted = DBA::insert(OrgSchema::TABLE_DOCUMENT, [
			'folder_id'   => $folderId,
			'title'       => $title !== '' ? $title : $filename,
			'description' => $description !== '' ? $description : null,
			'filename'    => $filename,
			'filetype'    => $filetype !== '' ? $filetype : 'application/octet-stream',
			'filesize'    => strlen($data),
			'storage_ref' => $reference,
			'visibility'  => self::sanitizeDocumentVisibility($visibility),
			'revision'    => $revision,
			'is_current'  => true,
			'supersedes'  => $supersedes,
			'uploaded_by' => $uploadedBy,
			'created'     => DateTimeFormat::utcNow(),
		]);

		if (!$inserted) {
			self::remove($reference);
			return 0;
		}

		return DBA::lastInsertId();
	}

	/**
	 * Deletes one revision and its stored bytes. Returns false when it is still the current
	 * revision of a document, so a history cannot be hollowed out by accident.
	 */
	public static function deleteDocument(int $id): bool
	{
		$document = self::documentById($id);

		if ($document === null) {
			return false;
		}

		$newerExists = DBA::exists(OrgSchema::TABLE_DOCUMENT, ['supersedes' => $id]);

		if ($document['is_current'] && $newerExists) {
			return false;
		}

		self::remove((string) $document['storage_ref']);

		return DBA::delete(OrgSchema::TABLE_DOCUMENT, ['id' => $id]);
	}

	// ------------------------------------------------------------ visibility

	/**
	 * Whether a viewer may see something at the given visibility.
	 *
	 * @param array<string, mixed>|null $member the viewer's membership record, null if none
	 */
	public static function canView(string $visibility, bool $isLoggedIn, bool $isOfficer, bool $isAdmin, ?array $member): bool
	{
		switch ($visibility) {
			case self::VIS_PUBLIC:
				return true;
			case self::VIS_ADMIN:
				return $isAdmin;
			case self::VIS_BOARD:
				return $isAdmin || $isOfficer;
			case self::VIS_MEMBERS:
			default:
				// Officers and administrators administer this material, so they keep access even
				// when they have no member record of their own - which is the normal case on a
				// freshly provisioned instance, where the administrator account predates the
				// addon. Requiring a member record here also made member-level announcements
				// invisible to everybody.
				if ($isAdmin || $isOfficer) {
					return true;
				}

				return $isLoggedIn && $member !== null && in_array($member['status'], ['active', 'pending'], true);
		}
	}

	/**
	 * Resolves a document's effective visibility against its folder.
	 */
	public static function effectiveVisibility(array $document, ?array $folder): string
	{
		if ($document['visibility'] !== self::VIS_INHERIT) {
			return (string) $document['visibility'];
		}

		return $folder !== null ? (string) $folder['visibility'] : self::VIS_MEMBERS;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recentDocuments(int $limit = 8): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_DOCUMENT,
			[],
			['is_current' => true],
			['order' => ['created' => 'DESC'], 'limit' => $limit]
		);
	}

	public static function documentCount(): int
	{
		return OrgSchema::ready() ? DBA::count(OrgSchema::TABLE_DOCUMENT, ['is_current' => true]) : 0;
	}

	// --------------------------------------------------------------- storage

	/**
	 * Writes bytes to Friendica's configured storage backend.
	 *
	 * @return string the backend reference, or '' on failure
	 */
	public static function store(string $data): string
	{
		try {
			return DI::storage()->put($data);
		} catch (\Throwable $e) {
			DI::logger()->error('OrgSuite: unable to store a document', ['exception' => $e]);

			return '';
		}
	}

	/**
	 * Reads bytes back from the storage backend.
	 */
	public static function retrieve(string $reference): ?string
	{
		try {
			$backend = DI::storage();

			if ($backend instanceof ICanReadFromStorage) {
				return $backend->get($reference);
			}

			$reader = DI::storageManager()->getByName((string) DI::config()->get('storage', 'name'));

			return $reader->get($reference);
		} catch (\Throwable $e) {
			DI::logger()->error('OrgSuite: unable to read a document', ['exception' => $e, 'reference' => $reference]);

			return null;
		}
	}

	public static function remove(string $reference): bool
	{
		if ($reference === '') {
			return false;
		}

		try {
			return (bool) DI::storage()->delete($reference);
		} catch (\Throwable $e) {
			DI::logger()->error('OrgSuite: unable to delete stored bytes', ['exception' => $e, 'reference' => $reference]);

			return false;
		}
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Normalises a folder's visibility.
	 *
	 * An unrecognised value falls back to the MOST RESTRICTIVE option, not the middle one.
	 * Failing open would mean that a typo, a renamed value or a hand-crafted POST silently
	 * published board-only minutes to every member, which is the one direction this must
	 * never fail in.
	 */
	public static function sanitizeVisibility(string $visibility): string
	{
		if (in_array($visibility, [self::VIS_PUBLIC, self::VIS_MEMBERS, self::VIS_BOARD, self::VIS_ADMIN], true)) {
			return $visibility;
		}

		DI::logger()->notice('OrgSuite: unrecognised folder visibility, restricting the folder instead', [
			'visibility' => $visibility,
		]);

		return self::VIS_BOARD;
	}

	public static function sanitizeDocumentVisibility(string $visibility): string
	{
		return in_array($visibility, [self::VIS_INHERIT, self::VIS_PUBLIC, self::VIS_MEMBERS, self::VIS_BOARD, self::VIS_ADMIN], true)
			? $visibility
			: self::VIS_INHERIT;
	}

	public static function visibilityLabel(string $visibility): string
	{
		return match ($visibility) {
			self::VIS_PUBLIC  => 'Anyone, including the public',
			self::VIS_MEMBERS => 'Members only',
			self::VIS_BOARD   => 'Board and officers only',
			self::VIS_ADMIN   => 'Site administrators only',
			self::VIS_INHERIT => 'Same as the folder',
			default           => $visibility,
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function visibilityOptions(): array
	{
		return [
			self::VIS_MEMBERS => 'Members only',
			self::VIS_BOARD   => 'Board and officers only',
			self::VIS_PUBLIC  => 'Anyone, including the public',
			self::VIS_ADMIN   => 'Site administrators only',
		];
	}

	public static function formatSize(int $bytes): string
	{
		if ($bytes >= 1048576) {
			return round($bytes / 1048576, 1) . ' MB';
		}

		if ($bytes >= 1024) {
			return round($bytes / 1024) . ' KB';
		}

		return $bytes . ' B';
	}

	private static function uniqueSlug(string $name): string
	{
		$base = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '');
		$base = trim($base, '-');

		if ($base === '') {
			$base = 'folder';
		}

		$slug   = $base;
		$suffix = 2;

		while (DBA::exists(OrgSchema::TABLE_FOLDER, ['slug' => $slug])) {
			$slug = $base . '-' . $suffix++;

			if ($suffix > 500) {
				$slug = $base . '-' . bin2hex(random_bytes(4));
				break;
			}
		}

		return $slug;
	}
}
