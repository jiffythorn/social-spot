<?php

/**
 * Copyright (C) 2026, Social Spot
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * A deliberately small dues ledger.
 *
 * This is not accounting software and should not pretend to be one. It answers the two
 * questions a volunteer treasurer actually gets asked: who has not paid, and how much did we
 * collect. Money is stored in integer cents so no rounding drift can appear in a report, and
 * a payment is never inferred from a charge's status: the two are recorded separately.
 */

declare(strict_types=1);

namespace Friendica\Addon\OrgSuite;

use Friendica\Database\DBA;
use Friendica\Util\DateTimeFormat;

final class DuesRepository
{
	public const PAYMENT_METHODS = ['card' => 'Card', 'ach' => 'Bank transfer (ACH)', 'check' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'];

	public const CHARGE_STATUS = ['open' => 'Outstanding', 'paid' => 'Paid', 'waived' => 'Waived', 'void' => 'Void'];

	/**
	 * Raises a charge. Returns the new id, or 0 on failure.
	 */
	public static function createCharge(int $uid, string $period, string $description, int $amountCents, ?string $dueDate, string $unit = '', int $createdBy = 0): int
	{
		if ($uid <= 0 || !OrgSchema::ready()) {
			return 0;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_CHARGE, [
			'uid'          => $uid,
			'unit'         => $unit,
			'period'       => $period,
			'description'  => $description,
			'amount_cents' => $amountCents,
			'due_date'     => $dueDate,
			'status'       => 'open',
			'created'      => DateTimeFormat::utcNow(),
			'created_by'   => $createdBy,
		]);

		return $inserted ? DBA::lastInsertId() : 0;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function chargesFor(int $uid): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_CHARGE,
			[],
			['uid' => $uid],
			['order' => ['created' => 'DESC']]
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function allCharges(string $status = '', int $limit = 500): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$condition = $status !== '' ? ['status' => $status] : [];

		return DBA::selectToArray(
			OrgSchema::TABLE_CHARGE,
			[],
			$condition,
			['order' => ['due_date' => 'ASC', 'id' => 'DESC'], 'limit' => $limit]
		);
	}

	public static function chargeById(int $id): ?array
	{
		if ($id <= 0 || !OrgSchema::ready()) {
			return null;
		}

		$charge = DBA::selectFirst(OrgSchema::TABLE_CHARGE, [], ['id' => $id]);

		return DBA::isResult($charge) ? $charge : null;
	}

	public static function setChargeStatus(int $id, string $status): bool
	{
		if (!array_key_exists($status, self::CHARGE_STATUS)) {
			return false;
		}

		return OrgSchema::ready() && DBA::update(OrgSchema::TABLE_CHARGE, ['status' => $status], ['id' => $id]);
	}

	public static function deleteCharge(int $id): bool
	{
		if (!OrgSchema::ready()) {
			return false;
		}

		DBA::delete(OrgSchema::TABLE_PAYMENT, ['charge_id' => $id]);

		return DBA::delete(OrgSchema::TABLE_CHARGE, ['id' => $id]);
	}

	/**
	 * Records a payment and settles the charge when it is fully covered.
	 */
	public static function recordPayment(int $chargeId, int $amountCents, string $method, string $reference, ?string $paidAt, string $note = '', int $recordedBy = 0): int
	{
		$charge = self::chargeById($chargeId);

		if ($charge === null || !array_key_exists($method, self::PAYMENT_METHODS)) {
			return 0;
		}

		$inserted = DBA::insert(OrgSchema::TABLE_PAYMENT, [
			'charge_id'    => $chargeId,
			'uid'          => (int) $charge['uid'],
			'amount_cents' => $amountCents,
			'method'       => $method,
			'reference'    => $reference,
			'paid_at'      => $paidAt,
			'note'         => $note,
			'recorded_by'  => $recordedBy,
			'created'      => DateTimeFormat::utcNow(),
		]);

		if (!$inserted) {
			return 0;
		}

		$paymentId = DBA::lastInsertId();

		if (self::paidTotal($chargeId) >= (int) $charge['amount_cents']) {
			self::setChargeStatus($chargeId, 'paid');
		}

		return $paymentId;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function paymentsForCharge(int $chargeId): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(OrgSchema::TABLE_PAYMENT, [], ['charge_id' => $chargeId], ['order' => ['paid_at' => 'DESC', 'id' => 'DESC']]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recentPayments(int $limit = 50): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		return DBA::selectToArray(
			OrgSchema::TABLE_PAYMENT,
			[],
			[],
			['order' => ['created' => 'DESC'], 'limit' => $limit]
		);
	}

	public static function paidTotal(int $chargeId): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		$stmt = DBA::p('SELECT COALESCE(SUM(`amount_cents`), 0) AS `total` FROM `' . OrgSchema::TABLE_PAYMENT . '` WHERE `charge_id` = ?', $chargeId);
		$row  = DBA::fetch($stmt);
		DBA::close($stmt);

		return (int) ($row['total'] ?? 0);
	}

	/**
	 * What a member still owes, counting only live charges.
	 */
	public static function balanceFor(int $uid): int
	{
		if (!OrgSchema::ready()) {
			return 0;
		}

		$owed = DBA::p(
			'SELECT COALESCE(SUM(`amount_cents`), 0) AS `total` FROM `' . OrgSchema::TABLE_CHARGE . '`
			 WHERE `uid` = ? AND `status` IN (\'open\', \'paid\')',
			$uid
		);
		$owedRow = DBA::fetch($owed);
		DBA::close($owed);

		$paid = DBA::p('SELECT COALESCE(SUM(`amount_cents`), 0) AS `total` FROM `' . OrgSchema::TABLE_PAYMENT . '` WHERE `uid` = ?', $uid);
		$paidRow = DBA::fetch($paid);
		DBA::close($paid);

		return (int) ($owedRow['total'] ?? 0) - (int) ($paidRow['total'] ?? 0);
	}

	/**
	 * Instance-wide totals for the console overview.
	 *
	 * @return array{outstanding_cents: int, collected_cents: int, open_charges: int, paid_charges: int}
	 */
	public static function summary(): array
	{
		$summary = ['outstanding_cents' => 0, 'collected_cents' => 0, 'open_charges' => 0, 'paid_charges' => 0];

		if (!OrgSchema::ready()) {
			return $summary;
		}

		$charges = DBA::p(
			'SELECT
				COALESCE(SUM(CASE WHEN `status` = \'open\' THEN `amount_cents` ELSE 0 END), 0) AS `outstanding`,
				SUM(CASE WHEN `status` = \'open\' THEN 1 ELSE 0 END) AS `open_count`,
				SUM(CASE WHEN `status` = \'paid\' THEN 1 ELSE 0 END) AS `paid_count`
			 FROM `' . OrgSchema::TABLE_CHARGE . '`
			 WHERE `status` IN (\'open\', \'paid\')'
		);
		$chargeRow = DBA::fetch($charges);
		DBA::close($charges);

		$payments = DBA::p('SELECT COALESCE(SUM(`amount_cents`), 0) AS `collected` FROM `' . OrgSchema::TABLE_PAYMENT . '`');
		$paymentRow = DBA::fetch($payments);
		DBA::close($payments);

		$summary['outstanding_cents'] = (int) ($chargeRow['outstanding'] ?? 0);
		$summary['open_charges']      = (int) ($chargeRow['open_count'] ?? 0);
		$summary['paid_charges']      = (int) ($chargeRow['paid_count'] ?? 0);
		$summary['collected_cents']   = (int) ($paymentRow['collected'] ?? 0);

		return $summary;
	}

	/**
	 * Members with an outstanding balance, for the treasurer's worklist.
	 *
	 * @return array<int, array{uid: int, balance_cents: int}>
	 */
	public static function outstandingByMember(int $limit = 200): array
	{
		if (!OrgSchema::ready()) {
			return [];
		}

		$stmt = DBA::p(
			'SELECT `uid`, SUM(`amount_cents`) AS `balance_cents`
			 FROM `' . OrgSchema::TABLE_CHARGE . '`
			 WHERE `status` = \'open\'
			 GROUP BY `uid`
			 HAVING `balance_cents` > 0
			 ORDER BY `balance_cents` DESC
			 LIMIT ' . (int) $limit
		);

		$rows = [];

		while ($row = DBA::fetch($stmt)) {
			$rows[] = ['uid' => (int) $row['uid'], 'balance_cents' => (int) $row['balance_cents']];
		}
		DBA::close($stmt);

		return $rows;
	}
}
