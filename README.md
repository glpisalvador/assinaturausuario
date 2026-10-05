# Assinatura de Usuário para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Cada pessoa cadastra a **própria assinatura**, e o GLPI a acrescenta automaticamente ao final dos acompanhamentos, soluções e tarefas que ela envia pela linha do tempo. A assinatura entra **uma única vez** e no lugar certo, sem duplicar em edições e sem aparecer em mensagens automáticas.

## O que o plugin faz

### A assinatura de cada pessoa
- Fica na aba **Assinatura** das **Preferências**, onde cada pessoa edita a sua. Quem pode alterar usuários também a encontra no cadastro do usuário.
- Editor de **texto rico** com imagens: logo, foto, ícones. Dá para colar a imagem direto no editor.
- **Variáveis** preenchidas na hora do envio: `{nome}`, `{primeiro_nome}`, `{email}`, `{telefone}`, `{celular}`, `{cargo}`, `{entidade}` e `{data}`.
- **Prévia ao vivo** de como a assinatura vai aparecer no chamado.
- **Modelo padrão** opcional, definido pelo administrador para quem não montou a própria assinatura.

### Quando a assinatura entra
- Somente ao **criar** o acompanhamento, a solução ou a tarefa. **Editar nunca altera** o conteúdo.
- Somente nos **formulários nativos** da linha do tempo. Ficam de fora a API, o coletor de e-mails, as ações automáticas e os outros plugins (estes podem ser liberados na configuração).
- **Nunca duplica:** se o texto já tem um bloco de assinatura, nada é acrescentado.
- Uma caixa **"Não assinar esta mensagem"** permite pular a assinatura numa mensagem específica.

### Imagens que funcionam em todo lugar
- As imagens da assinatura são guardadas pelo plugin, reduzidas para a largura máxima configurada, e entregues por um endereço com **token aleatório**. Por isso aparecem no chamado, para quem não tem login e nos e-mails de notificação.
- Formato configurável: **link** (padrão, o e-mail fica leve) ou **base64**, com a imagem embutida no texto.

## Configuração

Em *Configurar → Plugins → Assinatura de Usuário*:
- perfis que podem usar a assinatura;
- tipos em que ela entra: acompanhamento, solução, tarefa;
- formato das imagens e largura máxima;
- modelo padrão e se ele deve ser usado;
- liberar a assinatura em itens criados por outros plugins.

---

## Download e instalação

1. Baixe o arquivo `assinaturausuario-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/assinaturausuario/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/assinaturausuario
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install assinaturausuario -u <usuário administrador>
   php bin/console plugin:activate assinaturausuario
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/assinaturausuario` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install assinaturausuario -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).