<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/admin_layout.php';
Auth::requireAdmin();
$orgId = (int) $_SESSION['organization_id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$event = null;
if ($id) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id=? AND organization_id=? LIMIT 1');
    $stmt->execute([$id,$orgId]);
    $event = $stmt->fetch();
    if (!$event) { http_response_code(404); exit('Evento não encontrado.'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_check($_POST['csrf_token'] ?? null);
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = slugify(trim((string)($_POST['slug'] ?? $name)));
        $timezone = trim((string)($_POST['timezone'] ?? app_config()['timezone']));
        $startLocal = (string)($_POST['start_at'] ?? '');
        $endLocal = (string)($_POST['end_at'] ?? '');
        if ($name === '') throw new RuntimeException('Informe o nome do evento.');
        if ($startLocal === '' || $endLocal === '') throw new RuntimeException('Informe o início e o término.');
        $startUtc = utc_from_local($startLocal,$timezone);
        $endUtc = utc_from_local($endLocal,$timezone);
        if ($endUtc <= $startUtc) throw new RuntimeException('O término precisa ser posterior ao início.');
        $values = [
            $name,$slug,trim((string)($_POST['description'] ?? '')),trim((string)($_POST['location_name'] ?? '')),
            trim((string)($_POST['wifi_ssid'] ?? '')),trim((string)($_POST['expected_public_ip'] ?? '')) ?: null,
            preg_match('/^#[0-9a-fA-F]{6}$/',(string)($_POST['cover_color'] ?? '')) ? $_POST['cover_color'] : '#2563eb',
            $startUtc,$endUtc,$timezone,isset($_POST['registration_enabled'])?1:0,isset($_POST['notification_enabled'])?1:0,
            in_array($_POST['status'] ?? '', ['draft','active','finished'],true)?$_POST['status']:'active'
        ];
        if ($id) {
            $sql='UPDATE events SET name=?,slug=?,description=?,location_name=?,wifi_ssid=?,expected_public_ip=?,cover_color=?,start_at=?,end_at=?,timezone=?,registration_enabled=?,notification_enabled=?,status=?,updated_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?';
            db()->prepare($sql)->execute([...$values,$id,$orgId]);
            audit('update','events',$id,['name'=>$name]);
            flash('success','Evento atualizado.');
        } else {
            $sql='INSERT INTO events (organization_id,name,slug,description,location_name,wifi_ssid,expected_public_ip,cover_color,start_at,end_at,timezone,registration_enabled,notification_enabled,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())';
            db()->prepare($sql)->execute([$orgId,...$values]);
            $id=(int)db()->lastInsertId();
            audit('create','events',$id,['name'=>$name]);
            flash('success','Evento criado.');
        }
        redirect('admin/event_form.php?id='.$id);
    } catch (Throwable $e) {
        flash('error',$e->getMessage());
        redirect('admin/event_form.php'.($id?'?id='.$id:''));
    }
}

$defaults = [
'name'=>'','slug'=>'','description'=>'','location_name'=>'','wifi_ssid'=>'','expected_public_ip'=>'','cover_color'=>'#2563eb',
'timezone'=>app_config()['timezone'],'registration_enabled'=>1,'notification_enabled'=>1,'status'=>'active',
'start_at'=>gmdate('Y-m-d H:i:s'),'end_at'=>gmdate('Y-m-d H:i:s',time()+86400)
];
$event = array_merge($defaults,$event ?: []);
$startInput = (new DateTimeImmutable($event['start_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($event['timezone']))->format('Y-m-d\TH:i');
$endInput = (new DateTimeImmutable($event['end_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($event['timezone']))->format('Y-m-d\TH:i');
admin_header($id?'Editar evento':'Novo evento','events');
?>
<div class="toolbar"><a class="btn btn-secondary" href="<?= e(app_url('admin/events.php')) ?>">Voltar</a><?php if($id): ?><a class="btn btn-primary" target="_blank" href="<?= e(event_public_url($event)) ?>">Abrir página pública</a><?php endif; ?></div>
<div class="content-grid">
<section class="card form-card">
<form method="post" class="form-grid">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= $id ?>">
<label>Nome do evento<input name="name" required value="<?= e($event['name']) ?>"></label>
<label>Slug da URL<input name="slug" value="<?= e($event['slug']) ?>" placeholder="gerado pelo nome"></label>
<label class="span-2">Descrição<textarea name="description"><?= e($event['description']) ?></textarea></label>
<label>Local<input name="location_name" value="<?= e($event['location_name']) ?>" placeholder="Casa, auditório, loja..."></label>
<label>Nome do Wi-Fi (SSID)<input name="wifi_ssid" value="<?= e($event['wifi_ssid']) ?>" placeholder="Wi-Fi Evento"></label>
<label>IP público esperado — opcional<input name="expected_public_ip" value="<?= e($event['expected_public_ip']) ?>"><small>Somente informativo. O Push não depende deste IP.</small></label>
<label>Cor da capa<input name="cover_color" type="color" value="<?= e($event['cover_color']) ?>"></label>
<label>Início<input name="start_at" type="datetime-local" required value="<?= e($startInput) ?>"></label>
<label>Término<input name="end_at" type="datetime-local" required value="<?= e($endInput) ?>"></label>
<label>Fuso horário<input name="timezone" required value="<?= e($event['timezone']) ?>"></label>
<label>Status<select name="status"><option value="draft" <?= $event['status']==='draft'?'selected':'' ?>>Rascunho</option><option value="active" <?= $event['status']==='active'?'selected':'' ?>>Ativo</option><option value="finished" <?= $event['status']==='finished'?'selected':'' ?>>Finalizado</option></select></label>
<label class="checkbox-row"><input type="checkbox" name="registration_enabled" <?= $event['registration_enabled']?'checked':'' ?>> Permitir novos cadastros</label>
<label class="checkbox-row"><input type="checkbox" name="notification_enabled" <?= $event['notification_enabled']?'checked':'' ?>> Permitir ativação de notificações</label>
<button class="btn btn-primary span-2" type="submit">Salvar evento</button>
</form>
</section>
<aside>
<?php if($id): ?>
<section class="card panel">
<h2>Link e QR Code</h2>
<div class="event-link-box"><code id="event-url"><?= e(event_public_url($event)) ?></code><button class="btn btn-secondary btn-small" data-copy="#event-url">Copiar</button></div>
<div id="qrcode" class="qr-card" style="margin-top:14px"></div>
<p class="muted">O participante pode abrir pelo QR Code usando Wi-Fi ou 4G. O aparelho recebe Push depois de autorizar.</p>
</section>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>new QRCode(document.getElementById('qrcode'),{text:<?= json_encode(event_public_url($event)) ?>,width:190,height:190});</script>
<?php else: ?><div class="help-box">Após salvar, o sistema mostrará o link público e o QR Code deste evento.</div><?php endif; ?>
</aside>
</div>
<?php admin_footer(); ?>
