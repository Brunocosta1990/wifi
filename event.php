<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$slug=trim((string)($_GET['slug']??''));
$event=active_event_by_slug($slug);
if(!$event){http_response_code(404);?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>"><title>Evento não encontrado</title></head><body class="public-page"><main class="public-shell"><div class="public-content"><div class="alert alert-error">Evento não encontrado ou indisponível.</div></div></main></body></html><?php exit;}
$config=app_config();
$publicConfig=[
 'eventId'=>(int)$event['id'],'slug'=>$event['slug'],'vapidPublicKey'=>$config['vapid']['public_key'],
 'registerUrl'=>app_url('api/register.php'),'statusUrl'=>app_url('api/status.php'),'subscribeUrl'=>app_url('api/subscribe.php'),
 'unsubscribeUrl'=>app_url('api/unsubscribe.php'),'testPushUrl'=>app_url('api/test_push.php'),'testStatusUrl'=>app_url('api/test_status.php'),'historyUrl'=>app_url('api/history.php'),
 'pullUrl'=>app_url('api/pull_notification.php'),'serviceWorkerUrl'=>app_url('sw.js?v=1.0.1'),'serviceWorkerScope'=>(parse_url(app_url('/'), PHP_URL_PATH) ?: '/'),'storageKey'=>'alertawifi_participant_'.$event['id']
];
?>
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="<?= e($event['cover_color']) ?>">
<title><?= e($event['name']) ?> — <?= e($config['app_name']) ?></title>
<link rel="manifest" href="<?= e(app_url('manifest.php?slug='.urlencode($event['slug']))) ?>">
<link rel="apple-touch-icon" href="<?= e(app_url('assets/icons/icon-192.png')) ?>">
<link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head><body class="public-page">
<main class="public-shell">
<header class="event-hero" style="background:linear-gradient(135deg,<?= e($event['cover_color']) ?>,#7c3aed)">
<div class="event-icon">AW</div><h1><?= e($event['name']) ?></h1>
<?php if($event['description']):?><p><?= nl2br(e($event['description'])) ?></p><?php endif;?>
<div class="event-meta"><?php if($event['location_name']):?><span>📍 <?= e($event['location_name']) ?></span><?php endif;?><?php if($event['wifi_ssid']):?><span>📶 <?= e($event['wifi_ssid']) ?></span><?php endif;?></div>
</header>
<section class="public-content">
<div id="global-alert" class="alert hidden"></div>

<section id="registration-step" class="step-card">
<p class="eyebrow">PASSO 1</p><h2>Faça seu cadastro</h2><p class="muted">Cadastre este aparelho para receber os avisos deste evento.</p>
<?php if(!$event['registration_enabled']):?><div class="alert alert-error">Novos cadastros estão encerrados.</div><?php else:?>
<form id="registration-form" class="public-form">
<label>Nome<input name="name" required maxlength="160" autocomplete="name"></label>
<label>E-mail — opcional<input name="email" type="email" maxlength="190" autocomplete="email"></label>
<label>WhatsApp — opcional<input name="phone" maxlength="40" autocomplete="tel"></label>
<label>Tipo de participante — opcional<input name="participant_type" maxlength="80" placeholder="Visitante, equipe, palestrante..."></label>
<label class="consent-line"><input name="terms" type="checkbox" required> Li e aceito os termos de participação.</label>
<label class="consent-line"><input name="privacy" type="checkbox" required> Concordo com o uso destes dados para os avisos do evento. <a href="<?= e(app_url('privacy.php')) ?>" target="_blank">Ver privacidade</a>.</label>
<input name="website" class="hidden" tabindex="-1" autocomplete="off">
<button class="btn btn-primary" type="submit">Continuar</button>
</form><?php endif;?>
</section>

<section id="notification-step" class="step-card hidden">
<p class="eyebrow">PASSO 2</p><h2>Ative as notificações</h2><p class="muted">O navegador pedirá sua autorização. Depois disso, os avisos poderão chegar mesmo com esta página fechada.</p>
<div id="ios-guide" class="ios-guide hidden"><strong>No iPhone:</strong> toque em Compartilhar, escolha <em>Adicionar à Tela de Início</em>, abra o ícone criado e volte a tocar em ativar notificações.</div>
<div class="notification-status"><span id="status-dot" class="status-dot"></span><div><strong id="status-title">Notificações ainda não ativadas</strong><div id="status-text" class="muted">Aguardando autorização deste aparelho.</div></div></div>
<div class="toolbar" style="margin-top:16px;margin-bottom:0"><button id="enable-push" class="btn btn-primary" type="button">Ativar notificações</button><button id="test-push" class="btn btn-secondary hidden" type="button">Enviar teste para este aparelho</button><button id="disable-push" class="btn btn-danger hidden" type="button">Desativar</button></div>
</section>

<section id="ready-step" class="step-card hidden"><p class="eyebrow">TUDO PRONTO</p><h2>Este aparelho está cadastrado</h2><p>Você receberá as mensagens que o organizador programar para este evento.</p><div class="help-box"><strong>Importante:</strong> depois da autorização, o recebimento não depende de continuar no mesmo Wi-Fi. O aparelho também poderá receber usando 4G ou outra rede.</div></section>

<section class="step-card"><h2>Mensagens do evento</h2><div id="history-list" class="history-list"><p class="muted">Nenhuma mensagem enviada até agora.</p></div></section>
</section>
<footer class="footer-note">Notificações autorizadas voluntariamente · Você pode desativá-las a qualquer momento.</footer>
</main>
<script>window.ALERTA_WIFI_CONFIG=<?= json_encode($publicConfig,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(app_url('assets/js/public.js?v=1.0.1')) ?>"></script>
</body></html>
