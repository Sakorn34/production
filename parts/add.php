<?php
require_once 'D:/AppServ/secrets/production/parts.secrets.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = new PDO('sqlite:db.db');

    $tunnel_name = trim($_POST['site']);
    $subdomain   = trim($_POST['sub_domain']);

    $name_by = trim($_POST['name_by']);

    //------ post ข้อมูลไปยัง Cloudflare API
    $account_id = $cloudflareAccountId;
    $api_token  = $cloudflareApiToken;
    $zone_id    = $cloudflareZoneId;
    $tunnel_id  = "";

    $token   = "sudo cloudflared service install "; //  รอรับค่า Token
    $domain  = 'bitvisitor.net';
    $service = 'http://localhost:80'; // Service ที่จะเชื่อมต่อ

    $hostname = $subdomain . '.' . $domain;

    // ตรวจสอบซ้ำ
    $check = $db->prepare("SELECT COUNT(*) FROM tunnels WHERE site = ? OR sub_domain = ?");
    $check->execute(array($tunnel_name, $hostname));
    $exists = $check->fetchColumn();

    if ($exists > 0) {
        header("Location: index.php?error=duplicate&site=" . urlencode($tunnel_name) . "&sub_domain=" . urlencode($hostname));
        exit;
    }

    //-------------- Step 1: สร้าง token
    $url  = "https://api.cloudflare.com/client/v4/accounts/$account_id/cfd_tunnel";
    $data = array(
        "name"       => $tunnel_name,
        "config_src" => "cloudflare"
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer $api_token",
        "Content-Type: application/json"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
    } else {
        $json = json_decode($response, true);

        if ($json['success'] && isset($json['result'])) {
            $tunnel_id = $json['result']['id'];
            $token    .= $json['result']['token'];
        } else {
            header("Location: index.php?error=cf_tunnelName&site=" . urlencode($tunnel_name));
            exit;
        }
    }
    curl_close($ch);

    //-------------- Step 2: สร้าง CNAME record
    create_dns_record($zone_id, $subdomain, $tunnel_id, $domain, $api_token);

    //-------------- Step 3: ตั้งค่า hostname และ service
    $url  = "https://api.cloudflare.com/client/v4/accounts/$account_id/cfd_tunnel/$tunnel_id/configurations";
    $data = array(
        "config" => array(
            "ingress" => array(
                array(
                    "hostname"      => $hostname,
                    "service"       => $service,
                    "originRequest" => (object)array() // ว่างสำหรับ PHP 5.6
                ),
                array(
                    "service" => "http_status:404"
                )
            )
        )
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer $api_token",
        "Content-Type: application/json"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'cURL Error: ' . curl_error($ch);
    } else {
        echo "<pre>";
        print_r(json_decode($response, true));
        echo "</pre>";
    }
    curl_close($ch);

    //-------------- บันทึกลง DB
    $stmt = $db->prepare("INSERT INTO tunnels (site, sub_domain, tunnel_id, token, user_by) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute(array($tunnel_name, $hostname, $tunnel_id, $token, $name_by));

    header("Location: index.php?new_token=" . urlencode($token));
    exit;
}

// ฟังก์ชัน curl POST
function curl_post($url, $data, $token)
{
    $ch       = curl_init($url);
    $payload  = json_encode($data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        echo 'Curl error: ' . curl_error($ch);
        exit;
    }

    curl_close($ch);
    return json_decode($result, true);
}

// ฟังก์ชันสร้าง DNS record
function create_dns_record($zone_id, $subdomain, $tunnel_id, $domain, $token)
{
    $dns_url  = "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records";
    $dns_data = array(
        "type"    => "CNAME",
        "name"    => "$subdomain.$domain",
        "content" => "$tunnel_id.cfargotunnel.com",
        "ttl"     => 1,
        "proxied" => true
    );

    $response = curl_post($dns_url, $dns_data, $token);

    if (isset($response['errors']) && !empty($response['errors'])) {
        header("Location: index.php?error=cf_dns&site=" . urlencode($domain));
        exit;
    }

    if (isset($response['result'])) {
        echo "<p>DNS record created successfully: $subdomain.$domain</p>";
    } else {
        echo "<p>Unknown error occurred while creating DNS record.</p>";
    }
}
