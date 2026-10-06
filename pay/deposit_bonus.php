<?php
/**
 * Deposit promo + 2× turnover for every credit path
 * (PPay, Rhinopay, USDT, admin / manual approve).
 *
 * Rules (method tab wins over gateway name / create script):
 * - UPI / QR / Paytm / PhonePe: first-deposit gear + 2×(deposit + promo)
 *   even when the order was created by rhinopay.php (WatchPay/Rhinopay)
 *   or ppay.php (PPay). Gateway tag is not the bonus key.
 * - USDT-Bonus: 7% of INR motta + 2×
 * - USDT-NoBonus: none
 * - Manual / unknown: first-deposit promo + 2×
 *
 * Order create stamps deposit_order_meta.dharavahi → method_key.
 * Do not put DB credentials here — callers already have $conn.
 */

if (!function_exists('deposit_page_first_gears')) {
	$_dpc = __DIR__ . '/../deepanshu/helpers/deposit_page_config.php';
	if (is_readable($_dpc)) {
		require_once $_dpc;
	}
	if (!function_exists('deposit_page_first_gears')) {
		function deposit_page_first_gears()
		{
			return array();
		}
	}
}

if (!function_exists('deposit_bonus_normalize_method')) {
	function deposit_bonus_normalize_method($key)
	{
		$k = strtolower(trim((string)$key));
		if ($k === 'scan') {
			$k = 'qrcode';
		}
		$ok = array('upi' => true, 'qrcode' => true, 'paytm' => true, 'phonepe' => true, 'usdt' => true);
		return isset($ok[$k]) ? $k : '';
	}
}

if (!function_exists('deposit_bonus_kind_from_mula')) {
	function deposit_bonus_kind_from_mula($mula)
	{
		$m = trim((string)$mula);
		if ($m === '') {
			return array('method' => 'other', 'promo' => 'first');
		}
		if (strcasecmp($m, 'USDT-Bonus') === 0) {
			return array('method' => 'usdt', 'promo' => 'usdt_bonus');
		}
		if (strcasecmp($m, 'USDT-NoBonus') === 0 || stripos($m, 'USDT') === 0) {
			return array('method' => 'usdt', 'promo' => 'none');
		}
		if (preg_match('/^Phonepe_/i', $m) || preg_match('/^Phone-/i', $m) || stripos($m, 'Phone') !== false) {
			return array('method' => 'phonepe', 'promo' => 'first');
		}
		if (preg_match('/^(Pay-|PAY-)/i', $m) || stripos($m, 'Paytm') !== false) {
			return array('method' => 'paytm', 'promo' => 'first');
		}
		if (preg_match('/-QRR$/i', $m) || preg_match('/-QR2$/i', $m) || preg_match('/^QR-/i', $m)) {
			return array('method' => 'qrcode', 'promo' => 'first');
		}
		if (strcasecmp($m, 'Rhinopay') === 0) {
			return array('method' => 'upi', 'promo' => 'first');
		}
		return array('method' => 'other', 'promo' => 'first');
	}
}

if (!function_exists('deposit_bonus_kind_from_method')) {
	function deposit_bonus_kind_from_method($method, $mula)
	{
		$method = deposit_bonus_normalize_method($method);
		$mulaKind = deposit_bonus_kind_from_mula($mula);
		if ($method === 'usdt') {
			if ($mulaKind['promo'] === 'usdt_bonus') {
				return array('method' => 'usdt', 'promo' => 'usdt_bonus');
			}
			return array('method' => 'usdt', 'promo' => 'none');
		}
		if ($method === 'upi' || $method === 'qrcode' || $method === 'paytm' || $method === 'phonepe') {
			return array('method' => $method, 'promo' => 'first');
		}
		return $mulaKind;
	}
}

if (!function_exists('deposit_bonus_ensure_meta_table')) {
	function deposit_bonus_ensure_meta_table($conn)
	{
		if (!($conn instanceof mysqli)) {
			return false;
		}
		$sql = 'CREATE TABLE IF NOT EXISTS deposit_order_meta (
			dharavahi VARCHAR(64) NOT NULL,
			method_key VARCHAR(32) NOT NULL DEFAULT \'\',
			created_at DATETIME NOT NULL,
			PRIMARY KEY (dharavahi)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
		return (bool)$conn->query($sql);
	}
}

if (!function_exists('deposit_bonus_method_from_request')) {
	function deposit_bonus_method_from_request($fallbackMula = '')
	{
		$raw = '';
		if (isset($_GET['method'])) {
			$raw = (string)$_GET['method'];
		} elseif (isset($_POST['method'])) {
			$raw = (string)$_POST['method'];
		}
		$norm = deposit_bonus_normalize_method($raw);
		if ($norm !== '') {
			return $norm;
		}
		$kind = deposit_bonus_kind_from_mula($fallbackMula);
		$fromMula = deposit_bonus_normalize_method($kind['method']);
		return $fromMula !== '' ? $fromMula : (string)$kind['method'];
	}
}

if (!function_exists('deposit_bonus_stamp_method')) {
	function deposit_bonus_stamp_method($conn, $dharavahi, $methodKey)
	{
		if (!($conn instanceof mysqli)) {
			return false;
		}
		$order = trim((string)$dharavahi);
		$method = deposit_bonus_normalize_method($methodKey);
		if ($order === '' || $method === '') {
			return false;
		}
		if (!deposit_bonus_ensure_meta_table($conn)) {
			return false;
		}
		$now = date('Y-m-d H:i:s');
		$stmt = $conn->prepare(
			'INSERT INTO deposit_order_meta (dharavahi, method_key, created_at)
			 VALUES (?, ?, ?)
			 ON DUPLICATE KEY UPDATE method_key = VALUES(method_key)'
		);
		if (!$stmt) {
			error_log('deposit_bonus_stamp_method prepare failed: ' . $conn->error);
			return false;
		}
		$stmt->bind_param('sss', $order, $method, $now);
		$ok = $stmt->execute();
		if (!$ok) {
			error_log('deposit_bonus_stamp_method execute failed: ' . $stmt->error);
		}
		$stmt->close();
		return $ok;
	}
}

if (!function_exists('deposit_bonus_read_method')) {
	function deposit_bonus_read_method($conn, $dharavahi)
	{
		if (!($conn instanceof mysqli)) {
			return '';
		}
		$order = trim((string)$dharavahi);
		if ($order === '') {
			return '';
		}
		if (!deposit_bonus_ensure_meta_table($conn)) {
			return '';
		}
		$stmt = $conn->prepare('SELECT method_key FROM deposit_order_meta WHERE dharavahi = ? LIMIT 1');
		if (!$stmt) {
			return '';
		}
		$stmt->bind_param('s', $order);
		$stmt->execute();
		$row = ($res = $stmt->get_result()) ? $res->fetch_assoc() : null;
		$stmt->close();
		if (!$row) {
			return '';
		}
		return deposit_bonus_normalize_method($row['method_key']);
	}
}

if (!function_exists('deposit_bonus_resolve')) {
	function deposit_bonus_resolve($conn, $dharavahi, $mula)
	{
		$stored = deposit_bonus_read_method($conn, $dharavahi);
		if ($stored !== '') {
			return deposit_bonus_kind_from_method($stored, $mula);
		}
		return deposit_bonus_kind_from_mula($mula);
	}
}

if (!function_exists('deposit_bonus_apply')) {
	/**
	 * Credit method promo (if any) and add 2×(deposit+promo) to need_to_bet.
	 * USDT-NoBonus: no promo, no extra turnover.
	 * UPI / QR / Paytm / PhonePe: always 2×(deposit+promo), even if promo is 0.
	 * Idempotent via bonus_wager_log (deposit_promo).
	 */
	function deposit_bonus_apply($conn, $uid, $sid)
	{
		require_once __DIR__ . '/../deepanshu/helpers/wagering_helper.php';
		require_once __DIR__ . '/../deepanshu/helpers/wallet_helper.php';

		$uid = (int)$uid;
		$sid = (int)$sid;
		if ($uid <= 0 || $sid <= 0 || !($conn instanceof mysqli)) {
			error_log('deposit_bonus_apply skip bad args uid=' . $uid . ' sid=' . $sid);
			return false;
		}

		$stmt = $conn->prepare('SELECT motta, mula, dharavahi FROM thevani WHERE shonu = ? LIMIT 1');
		if (!$stmt) {
			error_log('deposit_bonus_apply prepare thevani failed: ' . $conn->error);
			return false;
		}
		$stmt->bind_param('i', $sid);
		$stmt->execute();
		$row = ($res = $stmt->get_result()) ? $res->fetch_assoc() : null;
		$stmt->close();
		if (!$row) {
			error_log('deposit_bonus_apply missing thevani sid=' . $sid);
			return false;
		}

		$depositInr = round((float)$row['motta'], 2);
		$mula = isset($row['mula']) ? (string)$row['mula'] : '';
		$dharavahi = isset($row['dharavahi']) ? (string)$row['dharavahi'] : '';
		$kind = deposit_bonus_resolve($conn, $dharavahi, $mula);
		$method = isset($kind['method']) ? (string)$kind['method'] : '';
		$promoInr = 0.0;
		$applyTurnover = false;

		if ($method === 'usdt' && $kind['promo'] === 'none') {
			return true;
		}

		if ($kind['promo'] === 'usdt_bonus') {
			$promoInr = round($depositInr * deposit_usdt_bonus_rate(), 2);
			$applyTurnover = true;
		} elseif ($method === 'upi' || $method === 'qrcode' || $method === 'paytm' || $method === 'phonepe') {
			$applyTurnover = true;
			if (!deposit_has_prior_success($conn, $uid, $sid)) {
				$promoInr = deposit_first_promo_inr($depositInr);
			} else {
				$promoInr = deposit_daily_extra_inr($method, $depositInr);
			}
		} elseif ($kind['promo'] === 'first' && !deposit_has_prior_success($conn, $uid, $sid)) {
			$promoInr = deposit_first_promo_inr($depositInr);
			$applyTurnover = $promoInr > 0;
		}

		if (!$applyTurnover) {
			return true;
		}

		if (!wagering_ensure_schema($conn)) {
			error_log('deposit_bonus_apply schema failed uid=' . $uid . ' sid=' . $sid);
			return false;
		}

		$chk = $conn->prepare(
			'SELECT 1 FROM bonus_wager_log WHERE user_id = ? AND bonus_type = ? AND source_id = ? LIMIT 1'
		);
		if ($chk) {
			$btype = 'deposit_promo';
			$chk->bind_param('isi', $uid, $btype, $sid);
			if ($chk->execute()) {
				$cr = $chk->get_result();
				if ($cr && $cr->num_rows > 0) {
					$chk->close();
					return true;
				}
			}
			$chk->close();
		}

		if ($promoInr > 0 && !wallet_credit_motta($conn, $uid, $promoInr)) {
			error_log('deposit_bonus_apply wallet promo failed uid=' . $uid . ' sid=' . $sid);
			return false;
		}

		$base = round($depositInr + $promoInr, 2);
		$mult = function_exists('deposit_turnover_multiplier') ? (float)deposit_turnover_multiplier() : 2.00;
		$ok = add_to_need_to_bet($conn, $uid, 'deposit_promo', $base, $sid, $mult, $promoInr);
		if (!$ok) {
			error_log('deposit_bonus_apply turnover failed uid=' . $uid . ' sid=' . $sid . ' base=' . $base);
		}
		return $ok;
	}
}
