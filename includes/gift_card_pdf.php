<?php
declare(strict_types=1);

/**
 * Gift card PDF generation (Dompdf).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Fetch remote image as a data URI for reliable Dompdf embedding.
 */
function gift_image_url_to_data_uri(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
        return '';
    }
    $body = '';
    $contentType = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'AllureOne-GiftCardPDF/1.0',
            ]);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if ($http >= 200 && $http < 300 && is_string($raw) && $raw !== '') {
                $body = $raw;
                $contentType = $ctype;
            }
            unset($ch);
        }
    }
    if ($body === '') {
        $ctx = stream_context_create([
            'http' => ['timeout' => 20, 'header' => "User-Agent: AllureOne-GiftCardPDF/1.0\r\n"],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (is_string($raw) && $raw !== '') {
            $body = $raw;
        }
    }
    if ($body === '') {
        return '';
    }
    $mime = 'image/jpeg';
    $ct = strtolower(trim(explode(';', $contentType)[0] ?? ''));
    if (str_contains($ct, 'png')) {
        $mime = 'image/png';
    } elseif (str_contains($ct, 'webp')) {
        $mime = 'image/webp';
    } elseif (str_contains($ct, 'gif')) {
        $mime = 'image/gif';
    } else {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (str_ends_with($path, '.png')) {
            $mime = 'image/png';
        } elseif (str_ends_with($path, '.webp')) {
            $mime = 'image/webp';
        } elseif (str_ends_with($path, '.gif')) {
            $mime = 'image/gif';
        }
    }

    // Dompdf needs GD for PNG; prefer JPEG when GD is unavailable.
    if ($mime !== 'image/jpeg' && !extension_loaded('gd')) {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($body);
}

function gift_local_file_to_data_uri(string $absolutePath): string
{
    if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
        return '';
    }
    $raw = @file_get_contents($absolutePath);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'image/jpeg',
    };
    if ($mime === 'image/png' && !extension_loaded('gd')) {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

/**
 * @param array{0:string,1:string,2:string} $row mark,label,value
 */
function gift_pdf_detail_row_html(string $mark, string $label, string $value, callable $e): string
{
    if (trim($value) === '') {
        return '';
    }

    return '<tr class="detail-row">'
        . '<td class="ico"><span class="ico-dot">' . $e($mark) . '</span></td>'
        . '<td class="lbl">' . $e($label) . '</td>'
        . '<td class="col">:</td>'
        . '<td class="val">' . nl2br($e($value), false) . '</td>'
        . '</tr>';
}

/**
 * @param array<string,mixed> $gift
 */
function gift_card_pdf_html(array $gift): string
{
    $brand = 'Allure Thai Spa & Wellness';
    $tagline = 'RELAX  •  REJUVENATE  •  BE WELL';
    $website = 'https://allurethaispa.in/';
    $amount = format_amount($gift['amount'] ?? null);
    $toName = trim((string) ($gift['recipient_name'] ?? ''));
    $message = trim((string) ($gift['message'] ?? ''));
    $buyer = trim((string) ($gift['sender_name'] ?? ''));
    $phone = trim((string) ($gift['buyer_phone'] ?? ''));
    $location = strtoupper(trim((string) ($gift['location'] ?? '')));
    $expiry = gift_card_expiry_date_display($gift['post_date'] ?? null);
    if ($expiry === '—') {
        $expiry = '';
    }
    $code = extract_gift_code((string) ($gift['gift_card_code'] ?? ''));
    $imageUrl = trim((string) ($gift['design_image_url'] ?? ''));
    $imageDataUri = $imageUrl !== '' ? gift_image_url_to_data_uri($imageUrl) : '';
    if ($imageDataUri === '') {
        $imageDataUri = gift_local_file_to_data_uri(__DIR__ . '/../assets/images/gift-card-default.jpg');
    }
    if ($imageDataUri === '') {
        $imageDataUri = gift_local_file_to_data_uri(__DIR__ . '/../assets/images/gift-card-default.webp');
    }
    $logoDataUri = gift_local_file_to_data_uri(__DIR__ . '/../assets/images/allure-thai-logo.jpg');
    if ($logoDataUri === '') {
        $logoDataUri = gift_local_file_to_data_uri(__DIR__ . '/../assets/images/allure-thai-logo.png');
    }

    $e = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $logoHtml = $logoDataUri !== ''
        ? '<img class="brand-logo" src="' . $e($logoDataUri) . '" alt="Allure Thai Spa & Wellness">'
        : '<span class="logo-mark">A</span><span class="brand-text"><div class="brand-name">' . $e($brand) . '</div><div class="tagline">' . $e($tagline) . '</div></span>';

    $details = '';
    $details .= gift_pdf_detail_row_html('*', 'Gift Amount', $amount, $e);
    $details .= gift_pdf_detail_row_html('*', 'To', $toName, $e);
    $details .= gift_pdf_detail_row_html('*', 'Message', $message, $e);
    if ($details !== '') {
        $details .= '<tr class="sep"><td colspan="4"><div class="sep-line"></div></td></tr>';
    }
    $details .= gift_pdf_detail_row_html('*', 'From', $buyer, $e);
    $details .= gift_pdf_detail_row_html('*', 'From Phone', $phone, $e);
    $details .= gift_pdf_detail_row_html('*', 'Location', $location, $e);
    $details .= gift_pdf_detail_row_html('*', 'Gift Card expiry date', $expiry, $e);

    $imageHtml = $imageDataUri !== ''
        ? '<img class="design" src="' . $e($imageDataUri) . '" alt="Gift design">'
        : '<div class="design-ph">Gift design image</div>';

    $codeBar = $code !== ''
        ? '<table class="code-bar" cellspacing="0" cellpadding="0"><tr>'
            . '<td class="code-left">* Gift Code</td>'
            . '<td class="code-right">' . $e($code) . '</td>'
            . '</tr></table>'
        : '';

    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 10mm 12mm; }
* { box-sizing: border-box; }
body {
  font-family: DejaVu Sans, sans-serif;
  color: #2c2418;
  font-size: 10pt;
  margin: 0;
  padding: 0;
  background: #ffffff;
}
.card {
  border: 2.5px solid #c4a35a;
  border-radius: 16px;
  padding: 18px 20px 14px;
  background: #ffffff;
}
@font-face {
  font-family: "GreatVibes";
  src: url("assets/fonts/GreatVibes-Regular.ttf") format("truetype");
  font-weight: normal;
  font-style: normal;
}
.header {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 14px;
}
.header td { vertical-align: middle; padding: 0; }
.brand-cell { width: 34%; }
.title-cell { width: 36%; text-align: center; }
.badge-cell { width: 30%; text-align: right; }
.brand-logo {
  height: 120px;
  width: auto;
  max-width: 520px;
  display: inline-block;
  vertical-align: middle;
}
.gift-title {
  text-align: center;
  font-family: "GreatVibes", "DejaVu Serif", cursive;
  font-size: 40pt;
  line-height: 1;
  color: #8a6a2f;
  margin: 0;
  letter-spacing: 1px;
  text-shadow: 0 1px 0 #f3e6c8;
}
.logo-mark {
  display: inline-block;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #c4a35a;
  color: #fff;
  text-align: center;
  line-height: 40px;
  font-family: DejaVu Serif, serif;
  font-size: 16pt;
  font-weight: bold;
  vertical-align: middle;
  margin-right: 10px;
}
.brand-text {
  display: inline-block;
  vertical-align: middle;
}
.brand-name {
  font-family: DejaVu Serif, serif;
  font-size: 20pt;
  font-weight: bold;
  color: #8a6a2f;
  line-height: 1.15;
  margin: 0;
}
.tagline {
  font-size: 8pt;
  letter-spacing: 1.6px;
  color: #8b8b8b;
  margin: 4px 0 0;
  text-transform: uppercase;
}
.badge {
  display: inline-block;
  border: 1.5px solid #c4a35a;
  background: #f3e6c8;
  color: #6b5428;
  font-family: DejaVu Serif, serif;
  font-size: 11pt;
  font-weight: bold;
  padding: 8px 16px;
  border-radius: 8px;
  letter-spacing: 0.6px;
}
.main {
  width: 100%;
  border-collapse: collapse;
}
.main > tr > td { vertical-align: top; padding: 0; }
.left {
  width: 38%;
  padding-right: 16px;
}
.right {
  width: 62%;
  padding-left: 2px;
}
.design {
  width: 100%;
  max-height: 115mm;
  height: auto;
  border-radius: 12px;
  border: 1px solid #e7d7b3;
  display: block;
}
.design-ph {
  width: 100%;
  height: 100mm;
  border-radius: 12px;
  border: 1px dashed #d4b57a;
  background: #faf6ee;
  color: #a0895a;
  text-align: center;
  padding-top: 42mm;
  font-size: 11pt;
}
table.details {
  width: 100%;
  border-collapse: collapse;
}
table.details td {
  padding: 6px 4px;
  vertical-align: top;
}
td.ico { width: 26px; padding-right: 4px; }
.ico-dot {
  display: inline-block;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #c4a35a;
  color: #fffdf8;
  text-align: center;
  line-height: 18px;
  font-size: 10pt;
  font-weight: bold;
}
td.lbl {
  width: 145px;
  color: #b08d3e;
  font-weight: bold;
  white-space: nowrap;
  font-size: 10pt;
}
td.col {
  width: 12px;
  color: #b08d3e;
  font-weight: bold;
}
td.val {
  color: #1f2937;
  font-weight: bold;
  line-height: 1.4;
  font-size: 10.5pt;
}
tr.sep td { padding: 9px 0; }
.sep-line {
  border-top: 1px solid #e5e7eb;
  height: 1px;
}
table.code-bar {
  margin-top: 14px;
  width: 100%;
  border-collapse: collapse;
  border: 1.5px solid #c4a35a;
  border-radius: 8px;
  background: #f4e7c9;
}
td.code-left {
  width: 30%;
  background: #e8d5a8;
  color: #5b4630;
  font-weight: bold;
  font-size: 10.5pt;
  padding: 10px 12px;
  border-right: 1px solid #c4a35a;
}
td.code-right {
  width: 70%;
  color: #111827;
  font-weight: bold;
  font-size: 13pt;
  letter-spacing: 0.5px;
  padding: 10px 14px;
}
.footer {
  margin-top: 16px;
  text-align: center;
}
.footer-note {
  font-size: 9.5pt;
  color: #6b7280;
  margin: 0 0 4px;
}
.footer-web {
  font-size: 12pt;
  font-weight: normal;
  color: #1e293b;
  margin: 0;
}
</style>
</head>
<body>
  <div class="card">
    <table class="header">
      <tr>
        <td class="brand-cell">
          ' . $logoHtml . '
        </td>
        <td class="title-cell"><div class="gift-title">Gift Card</div></td>
        <td class="badge-cell"><span class="badge">E-GIFT CARD</span></td>
      </tr>
    </table>
    <table class="main">
      <tr>
        <td class="left">' . $imageHtml . '</td>
        <td class="right">
          <table class="details">' . $details . '</table>
          ' . $codeBar . '
        </td>
      </tr>
    </table>
    <div class="footer">
      <p class="footer-note">Spa E-Gift Card - Valid at Allure Thai Spa &amp; Wellness Centres</p>
      <p class="footer-web">' . $e($website) . '</p>
    </div>
  </div>
</body>
</html>';
}

/**
 * @param array<string,mixed> $gift
 */
function gift_card_pdf_output(array $gift, string $filename = 'allure-gift-card.pdf'): void
{
    $prevDisplay = ini_get('display_errors');
    ini_set('display_errors', '0');

    $html = gift_card_pdf_html($gift);
    $projectRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->setChroot([$projectRoot]);
    $options->set('fontDir', $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts');

    $dompdf = new Dompdf($options);
    $fontFile = $projectRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'GreatVibes-Regular.ttf';
    if (is_file($fontFile)) {
        $dompdf->getFontMetrics()->registerFont(
            ['family' => 'GreatVibes', 'style' => 'normal', 'weight' => 'normal'],
            $fontFile
        );
    }
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $pdf = $dompdf->output();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $safeName = preg_replace('/[^A-Za-z0-9._\-]+/', '-', $filename) ?: 'allure-gift-card.pdf';
    if (!str_ends_with(strtolower($safeName), '.pdf')) {
        $safeName .= '.pdf';
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . (string) strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf;

    if ($prevDisplay !== false) {
        ini_set('display_errors', (string) $prevDisplay);
    }
}
