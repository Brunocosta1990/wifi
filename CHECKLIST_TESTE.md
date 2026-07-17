# Checklist de validação — versão 1.0.1

## Atualização

- [ ] backup realizado;
- [ ] arquivos extraídos sobre a instalação atual;
- [ ] `config/config.php` preservado;
- [ ] `storage/installed.lock` preservado;
- [ ] painel abre normalmente;
- [ ] Configurações mostra versão 1.0.1;
- [ ] conexão mostra `utf8mb4_unicode_ci`;
- [ ] tabelas mostram `utf8mb4_unicode_ci`.

## Aparelho

- [ ] página pública atualizada após a troca dos arquivos;
- [ ] Service Worker novo registrado;
- [ ] permissão de notificações ativa;
- [ ] teste mostra código HTTP 2xx;
- [ ] teste confirma “aparelho recebeu”;
- [ ] notificação aparece;
- [ ] notificação abre o link correto.

## Mensagens

- [ ] Enviar agora funciona;
- [ ] mensagem programada funciona;
- [ ] Cron executa a cada minuto;
- [ ] painel mostra quantidade aceita;
- [ ] painel mostra quantidade recebida;
- [ ] mensagem expirada não é entregue;
- [ ] mensagem cancelada não é entregue.

## Diagnóstico

- [ ] Configurações mostra último HTTP;
- [ ] Configurações mostra horário enviado;
- [ ] Configurações mostra horário recebido;
- [ ] log Push não mostra erro de certificado ou cURL;
- [ ] não aparece erro `Illegal mix of collations`.
