<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The File Cabinet.
 *
 * A structured folder tree so governing documents, minutes, budgets and forms stop getting
 * buried in a timeline. Three properties matter:
 *
 *  1. Access is re-checked on every single request, including downloads. Nothing is served
 *     from a filesystem path a visitor could guess.
 *  2. Officers upload; members read. Uploading replaces a document with a new revision while
 *     the old one is retained, because "which budget is current?" gets asked at every annual
 *     meeting.
 *  3. The folder set is seeded from the organization profile on install, so a new instance is
 *     useful before anyone configures anything.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite\Module;

use Friendica\Addon\OrgSuite\OrgConfig;
use Friendica\Addon\OrgSuite\OrgSchema;
use Friendica\Addon\OrgSuite\Text;
use Friendica\Addon\OrgSuite\VaultRepository;
use Friendica\Capabilities\ICanCreateResponses;
use Friendica\DI;
use Friendica\Util\Mimetype;

class Vault extends BaseOrgModule
{
	protected function get(array $request = [])
	{
		$action = Text::postValue($_GET, 'action');

		// The vault links to `orgsuite/documents/download/<id>`, so the route parameter is the
		// real signal that a file is being asked for. Triggering only on `?action=download`
		// meant clicking a document title re-rendered the vault page instead of serving the
		// file, while the link looked perfectly correct. `?action=download` is still honoured
		// so any existing bookmark keeps working.
		$id = (int) ($this->parameters['id'] ?? $request['id'] ?? 0);

		if ($action === 'download' || $id > 0) {
			$this->download($request);
		}
	}

	protected function post(array $request = [])
	{
		$this->requireLogin();

		$action = $this->cleanText($request, 'action', 32);

		switch ($action) {
			case 'create_folder':
			case 'update_folder':
			case 'delete_folder':
			case 'delete_document':
				$this->requireOfficer();
				break;
			case 'upload':
				$this->requireOfficer();
				break;
			default:
				DI::sysmsg()->addNotice('Unknown action.');
				$this->baseUrl->redirect('orgsuite/documents');
		}

		self::checkFormSecurityTokenRedirectOnError('orgsuite/documents', 'orgsuite_vault');

		switch ($action) {
			case 'create_folder':
				$this->createFolder($request);
				break;
			case 'update_folder':
				$this->updateFolder($request);
				break;
			case 'delete_folder':
				$this->deleteFolder($request);
				break;
			case 'delete_document':
				$this->deleteDocument($request);
				break;
			case 'upload':
				$this->uploadDocument($request);
				break;
		}

		$this->baseUrl->redirect('orgsuite/documents');
	}

	protected function content(array $request = []): string
	{
		$this->requireLogin();

		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$this->baseUrl->redirect('orgsuite');
		}

		if (!OrgSchema::ready()) {
			return $this->renderPage(
				'Documents',
				'',
				'documents',
				$this->emptyState('The document library is not ready yet. Run "bin/console dbstructure update" to create its tables.')
			);
		}

		$officer = $this->isOfficerOrAdmin();
		$showUpload = Text::postValue($_GET, 'action') === 'upload';

		$body = '';

		if ($officer) {
			$body .= $showUpload ? $this->uploadForm() : '<p class="orgsuite-actions">'
				. $this->link('Upload a document', 'orgsuite/documents?action=upload', 'primary')
				. '</p>';
		}

		$body .= $this->folders($officer);

		if ($officer) {
			$body .= $this->folderForm();
		}

		return $this->renderPage('Document library', 'Official documents, kept in one place.', 'documents', $body);
	}

	private function folders(bool $officer): string
	{
		$folders = VaultRepository::folders();
		$member  = $this->member();
		$html    = '';

		foreach ($folders as $folder) {
			if (!VaultRepository::canView((string) $folder['visibility'], true, $this->isOfficer(), $this->isSiteAdmin(), $member)) {
				continue;
			}

			$rows = [];

			foreach (VaultRepository::documentsInFolder((int) $folder['id']) as $document) {
				$visibility = VaultRepository::effectiveVisibility($document, $folder);

				if (!VaultRepository::canView($visibility, true, $this->isOfficer(), $this->isSiteAdmin(), $member)) {
					continue;
				}

				$title = '<a href="orgsuite/documents/download/' . (int) $document['id'] . '">'
					. Text::h((string) $document['title']) . '</a>';

				if ((int) $document['revision'] > 1) {
					$title .= ' ' . $this->badge('rev ' . (int) $document['revision'], 'muted');
				}

				$actions = $officer
					? $this->actionForm(
						'orgsuite/documents',
						'orgsuite_vault',
						['action' => 'delete_document', 'id' => (string) $document['id']],
						'Delete',
						'danger',
						'Delete "' . (string) $document['title'] . '"? This cannot be undone.'
					)
					: '';

				$rows[] = [
					$title,
					Text::h(VaultRepository::formatSize((int) $document['filesize'])),
					Text::h((string) $document['created']),
					$actions,
				];
			}

			$header = '<h3 class="orgsuite-folder__name">' . Text::h((string) $folder['name'])
				. ' ' . $this->badge(VaultRepository::visibilityLabel((string) $folder['visibility']), 'muted') . '</h3>';

			$description = $folder['description'] !== null
				? '<p class="orgsuite-folder__description">' . Text::h((string) $folder['description']) . '</p>'
				: '';

			$footer = '';

			if ($officer) {
				$footer = '<div class="orgsuite-folder__actions">'
					. $this->actionForm(
						'orgsuite/documents',
						'orgsuite_vault',
						['action' => 'delete_folder', 'id' => (string) $folder['id']],
						'Delete folder',
						'danger',
						'Delete the folder "' . (string) $folder['name'] . '"? Empty folders only.'
					)
					. '</div>';
			}

			$html .= '<section class="orgsuite-folder">'
				. $header . $description
				. $this->table(['Document', 'Size', 'Added', ''], $rows, 'No documents in this folder yet.')
				. $footer
				. '</section>';
		}

		if ($html === '') {
			return $this->card('Document library', $this->emptyState('No folders are visible to you yet.'));
		}

		return $html;
	}

	private function uploadForm(): string
	{
		$folders = [];
		$options = [];

		foreach (VaultRepository::folders() as $folder) {
			$options[(string) $folder['id']] = (string) $folder['name'];
		}

		$body = $this->textField('title', 'Document title', '', 'text', 'Leave empty to use the file name.', false)
			. $this->textArea('description', 'Description', '', 'One line telling members what this is.')
			. $this->selectField('folder_id', 'Folder', $options, (string) array_key_first($options), 'Where this belongs.')
			. $this->selectField(
				'visibility',
				'Who can read it',
				[VaultRepository::VIS_INHERIT => 'Same as the folder'] + VaultRepository::visibilityOptions(),
				VaultRepository::VIS_INHERIT
			)
			. '<label class="orgsuite-field"><span class="orgsuite-field__label">File <span class="orgsuite-required">*</span></span>'
			. '<input type="file" name="document" required /></label>'
			. '<p class="orgsuite-field__help">Up to ' . VaultRepository::formatSize(VaultRepository::MAX_UPLOAD_BYTES)
			. '. Uploading a new version of an existing document: pick the folder and give it the same title.</p>'
			. $this->hiddenField('action', 'upload')
			. '<p class="orgsuite-actions">' . $this->submitButton('Upload') . ' '
			. $this->link('Cancel', 'orgsuite/documents') . '</p>';

		return $this->card('Upload a document', $this->formStart('orgsuite/documents', 'orgsuite_vault') . $body . '</form>');
	}

	private function folderForm(): string
	{
		$body = $this->textField('name', 'Folder name', '', 'text', 'e.g. Governing documents', true)
			. $this->textArea('description', 'What belongs in it', '', '')
			. $this->selectField('visibility', 'Who can read it', VaultRepository::visibilityOptions(), VaultRepository::VIS_MEMBERS)
			. $this->textField('sort_order', 'Position', '0', 'number', 'Lower numbers appear first.')
			. $this->hiddenField('action', 'create_folder')
			. '<p class="orgsuite-actions">' . $this->submitButton('Create folder') . '</p>';

		return $this->card('New folder', $this->formStart('orgsuite/documents', 'orgsuite_vault') . $body . '</form>');
	}

	// ---------------------------------------------------------------- actions

	private function createFolder(array $request): void
	{
		$name = $this->cleanText($request, 'name', 191);

		if ($name === '') {
			DI::sysmsg()->addNotice('A folder needs a name.');

			return;
		}

		$id = VaultRepository::createFolder(
			$name,
			$this->cleanText($request, 'description', 1000),
			$this->cleanText($request, 'visibility', 16),
			$this->cleanInt($request, 'sort_order'),
			$this->localUid()
		);

		if ($id === 0) {
			DI::sysmsg()->addNotice('The folder could not be created.');

			return;
		}

		$this->audit('vault.folder.create', OrgSchema::TABLE_FOLDER, $id, ['name' => $name]);
		DI::sysmsg()->addInfo('Folder created.');
	}

	private function updateFolder(array $request): void
	{
		$id = $this->cleanInt($request, 'id');

		if (VaultRepository::folderById($id) === null) {
			DI::sysmsg()->addNotice('Folder not found.');

			return;
		}

		VaultRepository::updateFolder($id, [
			'visibility'  => $this->cleanText($request, 'visibility', 16),
			'description' => $this->cleanText($request, 'description', 1000),
		]);

		$this->audit('vault.folder.update', OrgSchema::TABLE_FOLDER, $id);
		DI::sysmsg()->addInfo('Folder updated.');
	}

	private function deleteFolder(array $request): void
	{
		$id = $this->cleanInt($request, 'id');

		if (!VaultRepository::deleteFolder($id)) {
			DI::sysmsg()->addNotice('That folder still contains documents, so it was not deleted. Move or delete them first.');

			return;
		}

		$this->audit('vault.folder.delete', OrgSchema::TABLE_FOLDER, $id);
		DI::sysmsg()->addInfo('Folder deleted.');
	}

	private function deleteDocument(array $request): void
	{
		$id = $this->cleanInt($request, 'id');

		if (!VaultRepository::deleteDocument($id)) {
			DI::sysmsg()->addNotice('That document could not be deleted. A newer revision may depend on it.');

			return;
		}

		$this->audit('vault.document.delete', OrgSchema::TABLE_DOCUMENT, $id);
		DI::sysmsg()->addInfo('Document deleted.');
	}

	private function uploadDocument(array $request): void
	{
		$file = $_FILES['document'] ?? null;

		if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
			DI::sysmsg()->addNotice($this->uploadErrorMessage(is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE));

			return;
		}

		$size = (int) ($file['size'] ?? 0);

		if ($size <= 0 || $size > VaultRepository::MAX_UPLOAD_BYTES) {
			DI::sysmsg()->addNotice('Files must be between 1 byte and ' . VaultRepository::formatSize(VaultRepository::MAX_UPLOAD_BYTES) . '.');

			return;
		}

		$tempPath = (string) ($file['tmp_name'] ?? '');
		$data     = $tempPath !== '' ? @file_get_contents($tempPath) : false;

		if ($data === false || $data === '') {
			DI::sysmsg()->addNotice('The uploaded file could not be read.');

			return;
		}

		$filename = basename((string) ($file['name'] ?? 'document'));
		$filename = preg_replace('/[^\w\.\- ]+/u', '_', $filename) ?? 'document';
		$filename = mb_substr($filename, 0, 200);

		$folderId = $this->cleanInt($request, 'folder_id');

		if (VaultRepository::folderById($folderId) === null) {
			DI::sysmsg()->addNotice('Choose a folder for the document.');

			return;
		}

		$title = $this->cleanText($request, 'title', 255);

		if ($title === '') {
			$title = pathinfo($filename, PATHINFO_FILENAME);
		}

		// Supersede rather than overwrite when an officer re-uploads the same title into the
		// same folder: the history is what makes the library auditable.
		$supersedes = $this->findCurrentByTitle($folderId, $title);

		$documentId = VaultRepository::storeDocument(
			$folderId,
			$title,
			$this->cleanText($request, 'description', 1000),
			$filename,
			(string) Mimetype::getContentType($filename),
			$data,
			$this->cleanText($request, 'visibility', 16),
			$this->localUid(),
			$supersedes
		);

		if ($documentId === 0) {
			DI::sysmsg()->addNotice('The document could not be stored. Check that the storage backend is configured.');

			return;
		}

		$this->audit('vault.document.upload', OrgSchema::TABLE_DOCUMENT, $documentId, [
			'folder_id'   => $folderId,
			'filename'    => $filename,
			'supersedes'  => $supersedes,
			'visibility'  => $this->cleanText($request, 'visibility', 16),
		]);

		DI::sysmsg()->addInfo($supersedes > 0
			? 'Document updated. The previous version is kept in its history.'
			: 'Document uploaded.');
	}

	private function uploadErrorMessage(int $code): string
	{
		return match ($code) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
			UPLOAD_ERR_PARTIAL                        => 'The upload was interrupted. Please try again.',
			UPLOAD_ERR_NO_FILE                        => 'Choose a file to upload.',
			UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not write the temporary file.',
			default                                   => 'The upload failed.',
		};
	}

	private function findCurrentByTitle(int $folderId, string $title): int
	{
		foreach (VaultRepository::documentsInFolder($folderId) as $document) {
			if (strcasecmp((string) $document['title'], $title) === 0) {
				return (int) $document['id'];
			}
		}

		return 0;
	}

	// --------------------------------------------------------------- download

	/**
	 * Streams a document after re-checking permissions against the *current* member record.
	 *
	 * @return never
	 */
	private function download(array $request): void
	{
		$id = (int) ($this->parameters['id'] ?? $request['id'] ?? 0);

		$document = VaultRepository::documentById($id);

		if ($document === null) {
			$this->httpError(404, 'Document not found');
		}

		$folder     = VaultRepository::folderById((int) $document['folder_id']);
		$visibility = VaultRepository::effectiveVisibility($document, $folder);

		// Pass the real sign-in state rather than assuming a signed-in reader: a public document
		// must still be reachable signed out, and nothing else may be.
		$isLoggedIn = DI::userSession()->getLocalUserId() > 0;

		if (!VaultRepository::canView($visibility, $isLoggedIn, $this->isOfficer(), $this->isSiteAdmin(), $this->member())) {
			DI::logger()->notice('OrgSuite: document access denied', [
				'document' => $id,
				'uid'      => $this->localUid(),
			]);
			$this->httpError(403, 'You do not have access to this document');
		}

		if (!OrgConfig::isFeatureEnabled(OrgConfig::FEATURE_VAULT)) {
			$this->httpError(404, 'Document not found');
		}

		$data = VaultRepository::retrieve((string) $document['storage_ref']);

		if ($data === null) {
			$this->httpError(404, 'The stored file could not be read');
		}

		$filename = preg_replace('/[\r\n"]+/', '', (string) $document['filename']) ?? 'document';

		// PDFs and images open in place; anything else is offered as a download. Either way
		// the browser is told not to sniff a different type.
		$disposition = str_starts_with((string) $document['filetype'], 'image/')
			|| (string) $document['filetype'] === 'application/pdf'
			? 'inline'
			: 'attachment';

		$this->response->setHeader($disposition . '; filename="' . $filename . '"', 'Content-Disposition');
		$this->response->setHeader('nosniff', 'X-Content-Type-Options');
		$this->response->setHeader('no-store', 'Cache-Control');

		$contentType = (string) $document['filetype'] !== '' ? (string) $document['filetype'] : 'application/octet-stream';

		$this->httpExit($data, ICanCreateResponses::TYPE_BLANK, $contentType);
	}
}
