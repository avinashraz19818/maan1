<?php
include "../../conn.php";
include "../../functions2.php";

//rechage chek added by kids_op serch me telegram 
header('Content-Type: application/json; charset=utf-8');
header('Strict-Transport-Security: max-age=31536000');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
header('Access-Control-Allow-Credentials: true');
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
header('Access-Control-Allow-Origin: ' . $origin);
header('vary: Origin');

date_default_timezone_set("Asia/Kolkata");
$shnunc = date("Y-m-d H:i:s");

$res = [
    'code' => 11,
    'msg' => 'Method not allowed',
    'msgCode' => 12,
    'serviceNowTime' => $shnunc,
];

$shonubody = file_get_contents("php://input");
$shonupost = json_decode($shonubody, true);
if ($_SERVER['REQUEST_METHOD'] != 'GET') {
    if (isset($shonupost['language']) && isset($shonupost['random']) && isset($shonupost['signature']) && isset($shonupost['timestamp'])) {
        $language = htmlspecialchars(mysqli_real_escape_string($conn, $shonupost['language']));
        $random = htmlspecialchars(mysqli_real_escape_string($conn, $shonupost['random']));
        $signature = htmlspecialchars(mysqli_real_escape_string($conn, $shonupost['signature']));
        $shonustr = '{"language":'.$language.',"random":"'.$random.'"}';
        $shonusign = strtoupper(md5($shonustr));
        
        if ($shonusign == $signature) {
            $bearer = explode(" ", $_SERVER['HTTP_AUTHORIZATION']);
            $author = $bearer[1]; 
            $is_jwt_valid = is_jwt_valid($author);
            $data_auth = json_decode($is_jwt_valid, 1);
            $byabaharkarta = $data_auth['payload']['id'];
// ---------------------------------------------------------------------------
// Recharge gate OFF: bina kisi recharge ke bhi har user game khol sakta hai.
// App ka gate (assets/js/index-DHU_0nuy.js):
//     if (allowNoRechargeGame == "1" || canDirectToGame) -> game open
//     warna min-recharge / "Game requires recharge to enter" popup
// Isliye neeche wali teeno values gate ko band kar deti hain. Recharge count /
// amount aaj bhi thevani se asli nikalte hain (reports ke liye sahi rahein).
// ---------------------------------------------------------------------------
$userId = (int) $byabaharkarta;
$userRechargeTimes = 0;
$userRechargeAmount = 0.0;
$rechargeSummary = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(motta), 0) AS amt FROM thevani WHERE balakedara = '$userId' AND sthiti = 1");
if ($rechargeSummary && ($summaryRow = $rechargeSummary->fetch_assoc())) {
    $userRechargeTimes = (int) $summaryRow['cnt'];
    $userRechargeAmount = (float) $summaryRow['amt'];
}

$res['data'] = [
    'canDirectToGame' => true,
    'userRechargeTimes' => $userRechargeTimes,
    'allowNoRechargeGame' => "1",
    'userRechargeAmount' => $userRechargeAmount,
    'lowestRechargeAmountToGame' => "0"
];
$res['code'] = 0;
$res['msg'] = 'Succeed';
$res['msgCode'] = 0;
$res['serviceNowTime'] = $shnunc;
http_response_code(200);
echo json_encode($res);

        } else {
            // Invalid signature
            $res['code'] = 5;
            $res['msg'] = 'Wrong signature';
            $res['msgCode'] = 3;
            http_response_code(200);
            echo json_encode($res);
        }
    } else {
        // Missing required parameters
        $res['code'] = 7;
        $res['msg'] = 'Param is Invalid';
        $res['msgCode'] = 6;
        http_response_code(200);
        echo json_encode($res);
    }
} else {
    // Invalid request method
    http_response_code(405);
    echo json_encode($res);
}
?>
