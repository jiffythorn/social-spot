<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Audit trail.
 *
 * Boards hand over to new volunteers every year or two, and disputes about "who approved
 * this member" or "who waived that assessment" are routine. Every privileged action in this
 * addon writes a row here so the answer does not depend on anyone's memory.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Util\DateTimeFormat;

final class AuditLog
{
	/**
	 * @param mixed $detail Anything JSON-encodable, or a plain string.
	 */
	public static function record(string $action, string $targetType = '', int $targetId = 0, $detail = null, ?int $actorUid = null): void
	{
		if (!OrgSchema::ready()) {
			return;
		}

		$actorUid = $actorUid ?? (int) DI::userSession()->getLocalUserId();

		if (is_array($detail) || is_object($detail)) {
			$detail = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		DBA::insert(OrgSchema::TABLE_AUDIT, [
			'actor_uid'   => $actorUid,
			'action'      => $action,
			'target_type' => $targetType,
			'target_id'   => $targetId,
			'detail'      => is_string($detail) ? Text::excerpt($detail, 2000) : null,
			'created'     => DateTimeFormat::utcNow(),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent(int $limit = 50): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_AUDIT,
			[],
			[],
			['order' => ['created' => 'DESC', 'id' => 'DESC'], 'limit' => $limit]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function forTarget(string $targetType, int $targetId, int $limit = 25): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_AUDIT,
			[],
			['target_type' => $targetType, 'target_id' => $targetId],
			['order' => ['created' => 'DESC'], 'limit' => $limit]
		);
	}
}
