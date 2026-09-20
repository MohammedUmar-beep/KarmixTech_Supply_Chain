<?php
/**
 * loyalty_helper.php
 * Reusable functions for awarding and redeeming loyalty points.
 *
 * Usage — award on sale:
 *   require_once 'includes/loyalty_helper.php';
 *   $pts_earned = award_loyalty_points($conn, $customer_id, $order_total, 'SalesOrder', $order_id);
 *
 * Usage — redeem at checkout (call before saving order):
 *   $discount = redeem_loyalty_points($conn, $customer_id, $points_to_redeem, $order_total, 'SalesOrder', $order_id);
 */

function award_loyalty_points(mysqli $conn, int $customer_id, float $order_total, string $ref_type = '', int $ref_id = 0): int
{
    $cfg = $conn->query("SELECT * FROM loyalty_config WHERE id=1 AND is_active=1")->fetch_assoc();
    if (!$cfg) return 0;

    $points   = (int) floor($order_total * floatval($cfg['points_per_unit']));
    if ($points <= 0) return 0;

    $current  = (int)($conn->query("SELECT loyalty_points_balance FROM customers WHERE id=$customer_id")->fetch_row()[0] ?? 0);
    $new_bal  = $current + $points;
    $rt       = $conn->real_escape_string($ref_type);
    $expires  = $cfg['points_expiry_days'] ? date('Y-m-d', strtotime('+'.$cfg['points_expiry_days'].' days')) : null;
    $exp_sql  = $expires ? "'$expires'" : 'NULL';

    $conn->query("UPDATE customers SET loyalty_points_balance=$new_bal WHERE id=$customer_id");
    $conn->query("INSERT INTO loyalty_points_ledger (customer_id,transaction_type,points,balance_after,reference_type,reference_id,notes,expires_at)
        VALUES ($customer_id,'Earned',$points,$new_bal,'$rt',$ref_id,'Auto-awarded on sale',$exp_sql)");

    return $points;
}

function redeem_loyalty_points(mysqli $conn, int $customer_id, int $points_to_redeem, float $order_total, string $ref_type = '', int $ref_id = 0): float
{
    $cfg     = $conn->query("SELECT * FROM loyalty_config WHERE id=1 AND is_active=1")->fetch_assoc();
    if (!$cfg) return 0.0;

    $current = (int)($conn->query("SELECT loyalty_points_balance FROM customers WHERE id=$customer_id")->fetch_row()[0] ?? 0);
    $min     = (int)$cfg['min_points_redeem'];
    $maxpct  = floatval($cfg['max_redemption_pct']) / 100;
    $rate    = floatval($cfg['redemption_rate']);

    // Validate
    if ($points_to_redeem < $min)    return 0.0;
    if ($points_to_redeem > $current) $points_to_redeem = $current;

    $discount = $points_to_redeem * $rate;
    $max_disc = $order_total * $maxpct;
    if ($discount > $max_disc) {
        $discount         = $max_disc;
        $points_to_redeem = (int)ceil($max_disc / $rate);
    }
    $points_to_redeem = min($points_to_redeem, $current);

    $new_bal = $current - $points_to_redeem;
    $rt      = $conn->real_escape_string($ref_type);
    $conn->query("UPDATE customers SET loyalty_points_balance=$new_bal WHERE id=$customer_id");
    $conn->query("INSERT INTO loyalty_points_ledger (customer_id,transaction_type,points,balance_after,reference_type,reference_id,notes)
        VALUES ($customer_id,'Redeemed',$points_to_redeem,$new_bal,'$rt',$ref_id,'Redeemed at checkout')");

    return round($discount, 2);
}

function get_loyalty_preview(mysqli $conn, int $customer_id, float $order_total): array
{
    $cfg     = $conn->query("SELECT * FROM loyalty_config WHERE id=1 AND is_active=1")->fetch_assoc();
    $balance = (int)($conn->query("SELECT loyalty_points_balance FROM customers WHERE id=$customer_id")->fetch_row()[0] ?? 0);
    if (!$cfg) return ['balance'=>$balance,'will_earn'=>0,'can_redeem_pts'=>0,'can_redeem_value'=>0];

    $will_earn      = (int)floor($order_total * floatval($cfg['points_per_unit']));
    $max_disc       = $order_total * (floatval($cfg['max_redemption_pct'])/100);
    $max_pts_by_pct = (int)floor($max_disc / floatval($cfg['redemption_rate']));
    $can_redeem_pts = min($balance, $max_pts_by_pct);
    $can_redeem_val = round($can_redeem_pts * floatval($cfg['redemption_rate']), 2);

    return [
        'balance'           => $balance,
        'will_earn'         => $will_earn,
        'can_redeem_pts'    => $can_redeem_pts,
        'can_redeem_value'  => $can_redeem_val,
        'min_pts'           => (int)$cfg['min_points_redeem'],
        'is_active'         => (bool)$cfg['is_active'],
    ];
}
