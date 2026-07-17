# Atualização 1.0.2 — diagnóstico completo de Web Push

## O que muda

- Padronização idempotente de tabelas e conexão em `utf8mb4_unicode_ci`.
- Logger centralizado em arquivo e banco, com mascaramento de dados sensíveis.
- Tabela `system_logs` e tela **Logs e diagnóstico**.
- Tela `admin/diagnostics.php` para validar ambiente, banco, Web Push, PWA e Cron.
- Correlation ID por envio, propagado para fila, Push, Service Worker e logs.
- Service Worker e JavaScript público enviam logs de instalação, permissão, assinatura, recebimento, exibição e clique.
- Cron registra heartbeat, lock e recuperação de mensagens presas.

## Como atualizar sem perder dados

1. Faça backup dos arquivos e do banco.
2. Substitua os arquivos da aplicação por esta versão.
3. Não substitua `config/config.php`.
4. Não apague `storage/installed.lock`.
5. Abra o painel administrativo; a migração 1.0.2 roda automaticamente.
6. Acesse **Logs e diagnóstico** e confirme que não há erros.
7. No celular, atualize a página pública e reative as notificações se necessário.

## Cron

Configure no cPanel para rodar a cada minuto:

```bash
curl -fsS "https://SEU-DOMINIO/cron_web.php?token=TOKEN" >/dev/null 2>&1
```

## Se ainda falhar

Envie os arquivos `storage/logs/push.log`, `storage/logs/service-worker.log`, `storage/logs/cron.log`, `storage/logs/php.log` e o correlation ID mostrado no teste.
