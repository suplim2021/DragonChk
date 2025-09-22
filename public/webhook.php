<?php
/* LINE OCR + GPT Vision
   - รับรูปจาก LINE
   - ส่งรูปให้ GPT Vision OCR + Extract รายการสินค้า
   - ตอบรายการกลับไปทาง LINE
*/
// Debug & Logging
const DEBUG                     = true; // ปิดเมื่อขึ้นจริง
const LOG_PATH                  = __DIR__ . '/line-ocr-debug.log';
const TRUNCATE_BYTES            = 1200;
// ไม่ตอบข้อความ text (รอเฉพาะรูป)
const REPLY_TO_TEXT             = false;
/* ========= HELPERS ========= */
function dbg($tag, $data=null){
  if(!DEBUG) return;
  $line = '['.date('Y-m-d H:i:s')."] {$tag}";
  if($data!==null){
    if (is_string($data)) $line .= " | $data";
    else $line .= " | ".json_encode($data, JSON_UNESCAPED_UNICODE);
  }
  @file_put_contents(LOG_PATH, $line.PHP_EOL, FILE_APPEND);
  error_log($line);
}
function respond200($msg='OK'){ http_response_code(200); echo $msg; exit; }
function hmacOk($raw){
  $sig = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
  $calc = base64_encode(hash_hmac('sha256', $raw, LINE_CHANNEL_SECRET, true));
  $ok = hash_equals($calc, $sig);
  if(!$ok){
    dbg('SIG_MISMATCH', ['got'=>$sig, 'calc'=>$calc, 'len_raw'=>strlen($raw)]);
  }
  return $ok;
}
// ===== Config from env if not defined =====
if (!defined('OPENAI_API_KEY'))            define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
if (!defined('OPENAI_MODEL'))              define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');
if (!defined('OPENAI_TEMPERATURE'))        define('OPENAI_TEMPERATURE', getenv('OPENAI_TEMPERATURE') !== false ? (string)getenv('OPENAI_TEMPERATURE') : '');
if (!defined('LINE_CHANNEL_SECRET'))       define('LINE_CHANNEL_SECRET', getenv('LINE_CHANNEL_SECRET') ?: '');
if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) define('LINE_CHANNEL_ACCESS_TOKEN', getenv('LINE_CHANNEL_ACCESS_TOKEN') ?: '');
function lineReply($replyToken, $text){
  $payload = [
    'replyToken' => $replyToken,
    'messages'   => [['type'=>'text','text'=>$text]]
  ];
  $ch = curl_init('https://api.line.me/v2/bot/message/reply');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>[
      'Content-Type: application/json',
      'Authorization: Bearer '.LINE_CHANNEL_ACCESS_TOKEN
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>15
  ]);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if($http!==200){
    dbg('LINE_REPLY_FAIL', ['http'=>$http, 'err'=>$err, 'res'=>mb_substr((string)$res,0,TRUNCATE_BYTES)]);
    return false;
  }
  return true;
}
function linePush($to, $text){
  if(!$to) return false;
  $payload = [
    'to' => $to,
    'messages' => [['type'=>'text','text'=>$text]],
  ];
  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>[
      'Content-Type: application/json',
      'Authorization: Bearer '.LINE_CHANNEL_ACCESS_TOKEN
    ],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>15
  ]);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if($http!==200){
    dbg('LINE_PUSH_FAIL', ['http'=>$http, 'err'=>$err, 'res'=>mb_substr((string)$res,0,TRUNCATE_BYTES)]);
    return false;
  }
  return true;
}
function fetchLineImageBinary($messageId){
  $url = "https://api-data.line.me/v2/bot/message/{$messageId}/content";
  $ch  = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.LINE_CHANNEL_ACCESS_TOKEN],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30
  ]);
  $raw   = curl_exec($ch);
  $http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $err   = curl_error($ch);
  curl_close($ch);
  if($raw===false || $http!==200){
    dbg('LINE_IMG_FAIL', ['http'=>$http, 'err'=>$err]);
    return ['ok'=>false, 'reason'=>'line_fetch_fail', 'http'=>$http, 'err'=>$err];
  }
  $headers = substr($raw, 0, $hsize);
  $body    = substr($raw, $hsize);
  $mime    = 'application/octet-stream';
  if (preg_match('/^Content-Type:\s*([^\r\n]+)/mi', $headers, $m)) {
    $mime = trim($m[1]);
  }
  dbg('LINE_IMG_OK', ['len'=>strlen($body), 'mime'=>$mime]);
  return ['ok'=>true, 'bin'=>$body, 'mime'=>$mime, 'http'=>$http];
}
/* ========= (Optional) Image Preprocess =========
   เปิดใช้ได้ถ้ามี Imagick เพื่อลดนอยส์/เพิ่มความคม
*/
function preprocessForOCR(string $imgBytes, string $mimeHint): string {
  if (!class_exists('Imagick')) return $imgBytes;
  try {
    $im = new Imagick();
    $im->readImageBlob($imgBytes);
    $im->setImageColorspace(Imagick::COLORSPACE_GRAY);
    $im->setImageType(Imagick::IMGTYPE_GRAYSCALE);
    $im->despeckleImage();
    if (method_exists($im,'contrastStretchImage')) {
      $qr = Imagick::getQuantumRange();
      $im->contrastStretchImage(0.01 * $qr['quantumRangeLong'], 0.99 * $qr['quantumRangeLong']);
    }
    $im->unsharpMaskImage(0.8, 0.5, 1.2, 0.02);
    if (method_exists($im,'deskewImage')) $im->deskewImage(1.5);
    $im->setImageFormat('png');
    $out = $im->getImagesBlob();
    $im->clear(); $im->destroy();
    return $out ?: $imgBytes;
  } catch (Throwable $e) {
    dbg('PREPROCESS_ERR', $e->getMessage());
    return $imgBytes;
  }
}
/* ========= เลือกบล็อกที่น่าจะเป็น "รายการ" (ยังคงไว้ถ้าต้องใช้ในอนาคต) ========= */
function pickLikelyItemsBlock(string $txt): string {
  $anchors = ['รายละเอียดสินค้า','รายการสินค้า','รายการส่งของ','รายการ','ตาราง','Table'];
  foreach ($anchors as $a) {
    $p = mb_stripos($txt, $a, 0, 'UTF-8');
    if ($p !== false) {
      $txt = trim(mb_substr($txt, $p, null, 'UTF-8'));
      break;
    }
  }
  $lines = preg_split('/\R+/', $txt);
  $tableLines = array_values(array_filter($lines, fn($l)=> preg_match('/\|.*\|/', $l)));
  if (count($tableLines) >= 2) {
    $tableLines = array_values(array_filter($tableLines, fn($l)=> !preg_match('/^\s*\|\s*-+.*-+\s*\|$/', $l)));
    return trim(implode("\n", $tableLines));
  }
  return trim(implode("\n", $lines));
}
/* ========= แปลง markdown table ให้เป็นแถวเรียบ (ยังคงไว้ถ้าต้องใช้) ========= */
function parseMarkdownTableToRows(string $table): array {
  $rows = [];
  foreach (preg_split('/\R+/', $table) as $ln) {
    if (!preg_match('/\|/', $ln)) continue;
    $cells = array_map('trim', array_filter(array_map('trim', explode('|', trim($ln, " \t|"))), fn($c)=>$c!==''));
    if (count($cells) < 2) continue;
    $name = $cells[1] ?? $cells[0];
    $qty  = null; $uom = ''; $opt = null;
    foreach ($cells as $c) {
      if (preg_match('/\b(\d{1,4})\b/u', $c, $m) && !preg_match('/บาท|฿|price|mm|ขนาด/i', $c)) { $qty = (int)$m[1]; break; }
    }
    if (preg_match('/(กล่อง|ชิ้น|แพ็ค|แพค|ลัง|ไม้|pack|pcs|box)/iu', $ln, $m)) { $uom = $m[1]; }
    $rows[] = "{$name} | ".($qty??'')." | {$uom} | ";
  }
  return $rows;
}
/* ========= ล้างชื่อสินค้า ========= */
function cleanItemName(string $s): string {
  $s = preg_replace('/\s+ราคา[:\s]*\d+[.,]?\d*\s*บาท/iu','',$s);
  $s = preg_replace('/\b\d+[.,]?\d*\s*บาท\b/u','',$s);
  $s = preg_replace('/\(\s*\d+\s*(คู่|pcs|ชิ้น)\s*\)/iu','',$s);
  $s = preg_replace('/\(\s*\d+\s*mm\.?\s*\)/iu','',$s);
  $s = preg_replace('/(หมายเหตุ|NOTE)[:：].*$/iu','',$s);
  $s = preg_replace('/\s{2,}/u',' ',$s);
  return trim($s, " \t-–—•·");
}
/* ========= Post-rule: UOM override ตามที่ผู้ใช้ต้องการ ========= */
function applyUomRules(array $item): array {
  $name = mb_strtolower($item['name'] ?? '', 'UTF-8');
  $opt  = mb_strtolower((string)($item['options'] ?? ''), 'UTF-8');
  $text = $name.' '.$opt;
  if (preg_match('/\b(ยกลัง|ลัง|ยก)\b/u', $text)) { $item['uom'] = 'ลัง'; return $item; }
  if (preg_match('/ซิลิโคน|silicone|sealant/i', $text)) { $item['uom'] = 'หลอด'; return $item; }
  $item['uom'] = '';
  return $item;
}
/* ========= Filtering: drop non-product rows ========= */
function isLikelyNonProductName(string $name): bool {
  $s = trim($name);
  if ($s==='') return true;
  if (mb_strlen($s, 'UTF-8') < 4) return true; // too short
  if (preg_match('/^[A-Z0-9_\-]{6,}$/', $s)) return true; // codes like N_F_F4_P27
  if (preg_match('/\b(COD|DROP\-?OFF|ORDER|ORDER\s*ID|IN\s*TRANSIT|HOME|ROS|MP|APOTG|SPX|J&T|J\s*&\s*T|TIKTOK|LAZADA|SHOPEE|EZ)\b/i', $s)) return true;
  if (preg_match('/\d{10,}/', $s)) return true; // long IDs
  return false;
}
function filterItemsForProducts(array $items): array {
  $out = [];
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $name = (string)($it['name'] ?? '');
    if (isLikelyNonProductName($name)) continue;
    $out[] = $it;
  }
  return $out;
}
/* ========= GPT Vision Extractor (OCR + Parse) ========= */
function extractItemsFromImageWithGPT($imgBytes, $mimeHint='application/octet-stream'){
  // (Optional) ปรับภาพให้คมขึ้น (ถ้ามี Imagick จะได้ PNG)
  $effBytes = preprocessForOCR($imgBytes, $mimeHint);
  $effMime  = ($effBytes !== $imgBytes && class_exists('Imagick')) ? 'image/png' : $mimeHint;
  // ส่งภาพให้ GPT ผ่าน data URL
  $dataUrl = 'data:'.$effMime.';base64,'.base64_encode($effBytes);
  $prompt = <<<TXT
You extract ONLY the product items from shipping labels/invoices (TikTok, Shopee, Lazada, J&T, SPX).
Focus strictly on the items table/section, typically labeled with headers like:
- "Product Name" / "SKU" / "Qty"
- Thai: "ชื่อสินค้า" / "ตัวเลือกสินค้า" / "จำนวน"
- Or a table with columns: # | ชื่อสินค้า | ตัวเลือกสินค้า | จำนวน
Ignore everything else (addresses, barcodes, order IDs, tracking IDs, big codes like "APOTG", "N_F_F4_...", "L1 F40-16 006B", "COD", dates, weight, service codes, sort codes, station codes, QR codes, etc.).
Never output any row that is just a code or route label (all-caps letters/digits/underscores/hyphens).
Return JSON only:
{
  "items": [
    {"name":"string","options":"string|null","qty":number|null,"uom":"string|null"}
  ]
}
Rules:
- Use only information visible in the image; do not guess. If something is not present, set it to null.
- "name" must be the product name only. Do NOT include price/currency or unit-size parentheses; move size/specs like (48mm) to "options" if they are options.
- If you see Thai words "ลัง" or "ยกลัง" in name/options, set uom to "ลัง".
- If multiple items are listed, include each item as a separate entry.
- If no items section exists, return {"items": []}.
TXT;
  $body = [
    'model' => OPENAI_MODEL,
    'messages' => [[
      'role' => 'user',
      'content' => [
        ['type'=>'text','text'=>$prompt],
        ['type'=>'image_url','image_url'=>['url'=>$dataUrl]]
      ]
    ]]
  ];
  if (OPENAI_TEMPERATURE !== '') {
    $t = (float)OPENAI_TEMPERATURE;
    $body['temperature'] = $t; // add only when provided to avoid model 400
  }
  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer '.OPENAI_API_KEY,
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90
  ]);
  $res  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($res===false) {
    dbg('OPENAI_VISION_CURL_ERR', $err);
    return ['ok'=>false,'step'=>'openai_vision','http'=>0,'err'=>$err];
  }
  $snippet = mb_substr((string)$res, 0, TRUNCATE_BYTES);
  dbg('OPENAI_VISION_RESP', ['http'=>$http, 'res_snippet'=>$snippet]);
  if ($http!==200) {
    return ['ok'=>false,'step'=>'openai_vision','http'=>$http,'res'=>$snippet];
  }
  $data = json_decode($res, true);
  $txt  = $data['choices'][0]['message']['content'] ?? '';
  // ตัด code fences ถ้ามี
  $txt  = preg_replace('/^```[a-z]*\s*|\s*```$/m', '', $txt);
  $obj  = json_decode($txt, true);
  if(!$obj && preg_match('/\{.*\}/s', $txt, $m)) $obj = json_decode($m[0], true);
  if(!$obj) return ['ok'=>false,'step'=>'openai_vision','http'=>$http,'err'=>'cannot_parse_json_from_model','content_snippet'=>mb_substr($txt,0,TRUNCATE_BYTES)];
  // Post-process: ล้างชื่อ + บังคับกฎ UOM (ลัง)
  if (!empty($obj['items']) && is_array($obj['items'])) {
    foreach ($obj['items'] as &$it) {
      if (!is_array($it)) continue;
      $it['name'] = cleanItemName($it['name'] ?? '');
      $it = applyUomRules($it);
      if ($it['uom'] === null) $it['uom'] = '';
    }
    unset($it);
    $obj['items'] = filterItemsForProducts($obj['items']);
  }
  return ['ok'=>true,'data'=>$obj];
}
/* ========= Render ========= */
function renderItemsText($obj){
  $items = $obj['items'] ?? [];
  if(!is_array($items) || !count($items)) return "ไม่พบรายการสินค้าในภาพนี้";
  $lines=[];
  foreach($items as $i=>$it){
    $name = $it['name']    ?? '-';
    $opt  = $it['options'] ?? null;
    $qty  = $it['qty']     ?? '-';
    $uom  = isset($it['uom']) ? (string)$it['uom'] : '';
    $lines[] = ($i+1).". ".$name.($opt? " ({$opt})":'')." — ".$qty.($uom!==''? " ".$uom:'');
  }
  return "สรุปรายการจากภาพ:\n".implode("\n",$lines);
}
/* ========= MAIN ========= */
$trace = 'T'.date('YmdHis').'-'.substr(bin2hex(random_bytes(3)),0,6);
dbg('REQ_BEGIN', ['trace'=>$trace, 'ip'=>$_SERVER['REMOTE_ADDR'] ?? null, 'script'=>($_SERVER['SCRIPT_FILENAME'] ?? null), 'model'=>OPENAI_MODEL]);
$raw = file_get_contents('php://input');
if($raw===''){ dbg('EMPTY_BODY'); respond200('OK'); }
if(!hmacOk($raw)){ http_response_code(403); echo "Bad signature"; exit; }
$payload = json_decode($raw, true);
if($payload===null && json_last_error()!==JSON_ERROR_NONE){
  dbg('BAD_JSON', json_last_error_msg());
  respond200('OK');
}
$events = $payload['events'] ?? [];
if(!$events){ dbg('NO_EVENTS'); respond200('OK'); }
// Flush HTTP response early to let LINE go while we process in background
ignore_user_abort(true);
@set_time_limit(0);
$__flushed = false;
if (function_exists('fastcgi_finish_request')) {
  http_response_code(200);
  echo 'OK';
  @ob_flush(); @flush();
  fastcgi_finish_request();
  $__flushed = true;
}
foreach($events as $e){
  if(($e['type'] ?? '')!=='message') continue;
  $msgType    = $e['message']['type'] ?? '';
  $replyToken = $e['replyToken'] ?? null;
  // รับเฉพาะรูป
  if($msgType==='image' && $replyToken){
    $messageId = $e['message']['id'] ?? null;
    if(!$messageId){ continue; }
    // 1) ดึงรูปจาก LINE
    $img = fetchLineImageBinary($messageId);
    if(!$img['ok']){ continue; }
    // 2) GPT Vision (OCR + Extract)
    $gx = extractItemsFromImageWithGPT($img['bin'], $img['mime'] ?? 'application/octet-stream');
    if(!$gx['ok']){
      $failMsg = "ขออภัย อ่านรายการจากภาพไม่สำเร็จ กรุณาส่งรูปใหม่ให้ชัดเจน หรือแจ้งแอดมินตรวจสอบโมเดล/การตั้งค่าอีกครั้ง";
      if (!lineReply($replyToken, $failMsg)) {
        $src = $e['source'] ?? [];
        $to  = $src['groupId'] ?? ($src['roomId'] ?? ($src['userId'] ?? null));
        if($to){ linePush($to, $failMsg); }
      }
      continue;
    }
    // 3) ตอบผล (reply ถ้า replyToken ยังไม่หมดอายุ, ถ้าล้มเหลวให้ push)
    $text = renderItemsText($gx['data']);
    $okReply = lineReply($replyToken, $text);
    if(!$okReply){
      $src = $e['source'] ?? [];
      $to  = $src['groupId'] ?? ($src['roomId'] ?? ($src['userId'] ?? null));
      if($to){ linePush($to, $text); }
    }
    continue;
  }
  // ข้อความ/ประเภทอื่น — เงียบ
  if($msgType==='text' && $replyToken){
    if (REPLY_TO_TEXT) {
      lineReply($replyToken, "ส่งรูปใบกำกับ/ใบส่งของมาได้เลย");
    }
    continue;
  }
}
dbg('REQ_END', ['trace'=>$trace]);
if (!$__flushed) respond200('OK');
?>