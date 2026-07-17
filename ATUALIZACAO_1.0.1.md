# Atualização 1.0.1 — instalação existente

Esta atualização corrige:

- erro `Illegal mix of collations`;
- comparação SQL que misturava `utf8mb4_general_ci` e `utf8mb4_unicode_ci`;
- conexão MySQL sem collation explícita;
- mensagens que podiam ficar presas em `processing`;
- ausência de confirmação entre “serviço Push aceitou” e “aparelho recebeu”;
- incompatibilidades do payload criptografado em alguns ambientes.

## Como atualizar sem perder dados

1. Faça backup da pasta atual e do banco.
2. Não apague `config/config.php`.
3. Não apague `storage/installed.lock`.
4. Extraia os arquivos da versão 1.0.1 sobre a instalação atual e confirme a substituição dos arquivos existentes.
5. Abra o painel administrativo.
6. A atualização do banco será executada automaticamente na primeira abertura.
7. Entre em **Configurações** e confirme:
   - versão 1.0.1;
   - conexão `utf8mb4 / utf8mb4_unicode_ci`;
   - todas as tabelas usando `utf8mb4_unicode_ci`.
8. Caso alguma tabela continue diferente, clique em **Reparar banco e collations**.
9. No celular, abra novamente a página pública do evento e atualize a página para instalar o novo Service Worker.
10. Toque em **Enviar teste para este aparelho**.

## Novo diagnóstico do teste

O teste agora mostra duas etapas:

1. o serviço Push aceitou o sinal, com o código HTTP;
2. o aparelho recebeu o sinal, buscou a mensagem no servidor e confirmou o recebimento.

No painel, em **Configurações**, é possível visualizar:

- código HTTP do último envio;
- data do envio;
- data da confirmação do aparelho;
- erro retornado pelo serviço Push;
- log recente dos disparos.

## Banco de dados

A atualização cria automaticamente:

- tabela `push_queue`;
- colunas de diagnóstico em `push_subscriptions`;
- coluna `received_at` em `message_deliveries`;
- correção das collations das tabelas existentes.
