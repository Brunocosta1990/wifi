# Alerta Wi-Fi MVP 1.0.1

Sistema em PHP + MySQL para cadastrar participantes, solicitar autorização de Web Push e enviar mensagens imediatas ou programadas.

## Recursos

- instalador automático pelo navegador;
- criação e atualização automática do banco;
- painel administrativo;
- eventos com link público e QR Code;
- cadastro e consentimento dos participantes;
- PWA e Service Worker;
- autorização Web Push;
- envio imediato e agendado;
- várias mensagens durante o dia;
- validade das mensagens;
- Cron Job;
- confirmação de sinal aceito e aparelho recebido;
- diagnóstico de HTTP, erros e logs;
- reparo automático de charset e collation;
- funcionamento sem Composer, Firebase ou OneSignal.

## Requisitos

- PHP 8.1 ou superior;
- MySQL 5.7+ ou MariaDB compatível;
- extensões `pdo_mysql`, `openssl`, `curl`, `json` e `mbstring`;
- função `openssl_pkey_derive`;
- HTTPS ativo;
- Cron Job com frequência de um minuto.

## Nova instalação

1. Crie um banco MySQL vazio e um usuário com todas as permissões no banco.
2. Extraia o ZIP na raiz do domínio ou subdomínio.
3. Garanta permissão de escrita nas pastas `config` e `storage`.
4. Acesse o domínio por HTTPS.
5. Preencha o instalador.
6. O sistema criará tabelas, administrador, chaves VAPID, token do Cron e evento de teste.

## Atualização de uma instalação 1.0.0

Leia `ATUALIZACAO_1.0.1.md`.

Resumo:

1. faça backup;
2. extraia a versão nova sobre a antiga;
3. não apague `config/config.php` nem `storage/installed.lock`;
4. abra o painel;
5. acesse **Configurações** e valide as collations;
6. atualize a página pública no celular;
7. faça um novo teste.

## Cron Job

No painel, abra **Configurações** e copie o comando apresentado. Exemplo:

```bash
curl -fsS "https://SEU-DOMINIO/cron_web.php?token=TOKEN" >/dev/null 2>&1
```

Sem Cron, o envio imediato funciona, mas as mensagens futuras não serão processadas automaticamente.

## Como o Push funciona na versão 1.0.1

```text
Painel programa a mensagem
        ↓
Servidor grava a mensagem na fila
        ↓
Servidor envia um sinal Web Push
        ↓
Service Worker recebe o sinal
        ↓
Celular busca a mensagem no servidor
        ↓
Notificação é exibida
        ↓
Sistema registra a confirmação do aparelho
```

Essa abordagem reduz incompatibilidades de criptografia do conteúdo e permite diferenciar:

- **aceito:** o serviço Push aceitou o sinal;
- **recebido:** o aparelho executou o Service Worker e buscou a mensagem;
- **falha:** o serviço Push ou a conexão retornou erro.

## Primeiro teste

1. Entre no painel.
2. Abra o evento de teste.
3. Abra a página pública pelo celular.
4. Faça o cadastro.
5. Autorize as notificações.
6. Toque em **Enviar teste para este aparelho**.
7. Aguarde a confirmação exibida na página.
8. Abra **Configurações** no painel para conferir HTTP, horário recebido e logs.
9. Crie uma mensagem para alguns minutos depois e confirme o Cron.

## iPhone

No iPhone/iPad compatível:

1. abra a página no Safari;
2. toque em **Compartilhar**;
3. escolha **Adicionar à Tela de Início**;
4. abra pelo ícone;
5. ative as notificações.

## Segurança

- preserve `config/config.php`;
- não compartilhe a chave privada VAPID;
- mantenha HTTPS ativo;
- não apague `storage/installed.lock`;
- proteja o painel com senha forte;
- adapte os textos de privacidade antes do uso comercial.

## Estrutura

```text
/admin              Painel e diagnóstico
/api                Cadastro, assinatura, teste e busca das mensagens
/assets             CSS, JavaScript e ícones
/config             Configuração privada
/cron               Processamento agendado
/install            Instalador
/src                Banco, migrações, Push, fila e agendador
/storage            Locks e logs
sw.js               Service Worker
```
