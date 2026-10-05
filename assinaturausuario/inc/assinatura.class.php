<?php

/**
 * Plugin Assinatura de Usuário - a assinatura de cada pessoa e a regra de quando ela entra.
 *
 * Regras que evitam assinatura duplicada ou em lugar errado:
 *  - só entra ao CRIAR o item (edições nunca alteram o conteúdo);
 *  - só no formulário nativo do GLPI daquele tipo (outros plugins, API, coletor de e-mail e
 *    tarefas automáticas ficam de fora, salvo se a configuração liberar outros plugins);
 *  - nunca entra se o conteúdo já tem um bloco de assinatura (classe MARCA), que sobrevive ao
 *    filtro de HTML do GLPI e à edição no editor;
 *  - uma vez por item e, no modo nativo, uma vez por requisição.
 */
class PluginAssinaturausuarioAssinatura extends CommonDBTM
{
    public const TABELA = 'glpi_plugin_assinaturausuario_assinaturas';
    public const MARCA = 'assinaturausuario-assinatura';

    private static array $assinaturausuarioFeitos = [];

    /** Só os testes automatizados (via reflexão) simulam uma requisição web a partir da linha de comando */
    private static bool $assinaturausuarioSimularWeb = false;

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Assinaturas' : 'Assinatura';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return (bool) Session::getLoginUserID();
    }

    public static function canCreate(): bool
    {
        return (bool) Session::getLoginUserID();
    }

    // =====================================================================
    // Leitura e gravação
    // =====================================================================

    public static function doUsuario(int $usuario): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => self::TABELA, 'WHERE' => ['users_id' => $usuario], 'LIMIT' => 1]) as $r) {
            $tipos = json_decode((string) $r['tipos'], true);
            $r['tipos'] = is_array($tipos) ? array_values(array_intersect(array_keys(PluginAssinaturausuarioConfig::TIPOS), $tipos)) : array_keys(PluginAssinaturausuarioConfig::TIPOS);
            return $r;
        }
        return null;
    }

    public static function salvar(int $usuario, string $conteudo, bool $ativo, array $tipos): bool
    {
        global $DB;
        $dados = [
            'conteudo'  => $conteudo,
            'is_active' => $ativo ? 1 : 0,
            'tipos'     => json_encode(array_values(array_intersect(array_keys(PluginAssinaturausuarioConfig::TIPOS), array_map('strval', $tipos)))),
        ];
        if (self::doUsuario($usuario) !== null) {
            return (bool) $DB->update(self::TABELA, $dados, ['users_id' => $usuario]);
        }
        return (bool) $DB->insert(self::TABELA, $dados + ['users_id' => $usuario]);
    }

    public static function remover(int $usuario): bool
    {
        global $DB;
        return (bool) $DB->delete(self::TABELA, ['users_id' => $usuario]);
    }

    /** Sem texto nem imagem */
    public static function vazio(?string $html): bool
    {
        $texto = str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags((string) $html, '<img>'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim($texto) === '';
    }

    /**
     * Assinatura que vale para a pessoa: a própria (se ativa) ou o modelo da empresa, quando a
     * configuração manda usá-lo para quem não tem assinatura. Retorna [conteudo, tipos] ou null.
     */
    public static function efetiva(int $usuario): ?array
    {
        $propria = self::doUsuario($usuario);
        if ($propria !== null) {
            if (empty($propria['is_active']) || self::vazio($propria['conteudo'])) {
                return null;
            }
            return ['conteudo' => (string) $propria['conteudo'], 'tipos' => $propria['tipos'], 'origem' => 'propria'];
        }
        $modelo = (string) PluginAssinaturausuarioConfig::getConfig('modelo');
        if (PluginAssinaturausuarioConfig::getConfig('usar_modelo') === '1' && !self::vazio($modelo)) {
            return ['conteudo' => $modelo, 'tipos' => array_keys(PluginAssinaturausuarioConfig::TIPOS), 'origem' => 'modelo'];
        }
        return null;
    }

    // =====================================================================
    // Normalização das imagens (a causa do base64 que virava texto)
    // =====================================================================

    /**
     * Toda imagem da assinatura vira um arquivo do plugin com URL estável:
     *  - imagens coladas no editor (upload temporário do GLPI, identificadas pelo id = tag);
     *  - base64 (data:image/...);
     *  - documentos do GLPI (document.send.php?docid=), usados pela 1.x;
     *  - imagens do próprio plugin são mantidas; links http(s) externos também.
     * Qualquer outra coisa (blob:, file:, caminhos temporários) é descartada.
     */
    public static function normalizar(string $html, int $usuario, array $uploads = [], bool $semVerificar = false): string
    {
        if (trim($html) === '') {
            return '';
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $anterior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><div id="assinaturausuario-raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        $raiz = $dom->getElementById('assinaturausuario-raiz');
        if ($raiz === null) {
            return '';
        }
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = trim($img->getAttribute('src'));
            $id = trim($img->getAttribute('id'), '#');
            $bytes = null;
            $manter = false;
            if ($id !== '' && isset($uploads[$id])) {
                $bytes = (string) file_get_contents($uploads[$id]);
            } elseif (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $src, $m)) {
                $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true) ?: null;
            } elseif (($tokens = PluginAssinaturausuarioImagem::tokensNoHtml($src)) && PluginAssinaturausuarioImagem::porToken($tokens[0]) !== null) {
                $img->setAttribute('src', PluginAssinaturausuarioImagem::url($tokens[0]));
                $manter = true;
            } elseif (preg_match('#document\.send\.php\?(?:[^"\']*?&(?:amp;)?)?docid=(\d+)#i', html_entity_decode($src), $m)) {
                $bytes = PluginAssinaturausuarioImagem::bytesDocumento((int) $m[1], !$semVerificar);
            } elseif (preg_match('#^https?://#i', $src) && !str_contains($src, '/front/document.send.php')) {
                $manter = true;
            }
            if ($bytes !== null && !$manter) {
                $salva = PluginAssinaturausuarioImagem::guardar($bytes, $usuario);
                if ($salva !== null) {
                    [$token, $largura] = $salva;
                    $img->setAttribute('src', PluginAssinaturausuarioImagem::url($token));
                    $atual = (int) $img->getAttribute('width');
                    if ($atual <= 0 || $atual > $largura) {
                        $img->setAttribute('width', (string) $largura);
                        $img->removeAttribute('height');
                    }
                    $manter = true;
                }
            }
            if (!$manter) {
                $pai = $img->parentNode;
                $pai->removeChild($img);
                // Parágrafo que só tinha a imagem descartada some junto
                if ($pai instanceof DOMElement && in_array(strtolower($pai->nodeName), ['p', 'div', 'span', 'a'], true) && $pai !== $raiz && trim(str_replace("\xC2\xA0", '', $pai->textContent)) === '' && $pai->getElementsByTagName('img')->length === 0) {
                    $pai->parentNode->removeChild($pai);
                }
                continue;
            }
            foreach (['id', 'data-upload_id', 'data-mce-src', 'data-mce-selected', 'srcset', 'loading'] as $a) {
                $img->removeAttribute($a);
            }
            // alt vazio sai como atributo sem valor no saveHTML: usa um texto curto
            if (trim($img->getAttribute('alt')) === '') {
                $img->setAttribute('alt', 'Assinatura');
            }
        }
        $saida = '';
        foreach ($raiz->childNodes as $no) {
            $saida .= $dom->saveHTML($no);
        }
        // Parágrafos vazios no fim (sobras do editor)
        $saida = (string) preg_replace('#(?:\s*<p>(?:\s|&nbsp;|\xC2\xA0|<br\s*/?>)*</p>)+\s*$#u', '', $saida);
        return trim($saida);
    }

    // =====================================================================
    // Montagem do bloco final
    // =====================================================================

    /** Valores das variáveis para a pessoa (já escapados para HTML) */
    public static function variaveis(int $usuario, int $entidade = -1): array
    {
        $u = new User();
        $u->getFromDB($usuario);
        $f = $u->fields ?? [];
        $primeiro = trim((string) ($f['firstname'] ?? ''));
        $nome = trim($primeiro . ' ' . trim((string) ($f['realname'] ?? '')));
        if ($nome === '') {
            $nome = (string) ($f['name'] ?? '');
        }
        $email = $usuario > 0 ? (string) UserEmail::getDefaultForUser($usuario) : '';
        $cargo = !empty($f['usertitles_id']) ? (string) Dropdown::getDropdownName('glpi_usertitles', (int) $f['usertitles_id']) : '';
        $nomeEntidade = '';
        $entidade = $entidade >= 0 ? $entidade : (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $e = new Entity();
        if ($e->getFromDB($entidade)) {
            $nomeEntidade = (string) $e->fields['name'];
        }
        $valores = [
            '{nome}'          => $nome,
            '{primeiro_nome}' => $primeiro !== '' ? $primeiro : $nome,
            '{email}'         => $email,
            '{telefone}'      => (string) ($f['phone'] ?? ''),
            '{celular}'       => (string) ($f['mobile'] ?? ''),
            '{cargo}'         => $cargo === '&nbsp;' ? '' : $cargo,
            '{entidade}'      => $nomeEntidade,
            '{data}'          => date('d/m/Y'),
        ];
        return array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $valores);
    }

    /**
     * Bloco final da assinatura. $envio = true: imagens com URL absoluta (ou embutidas, conforme
     * a configuração), como vai para o chamado e para os e-mails; false: prévia na tela.
     */
    public static function montar(string $conteudo, int $usuario, int $entidade = -1, bool $envio = true): string
    {
        $html = strtr($conteudo, self::variaveis($usuario, $entidade));
        $embutir = $envio && PluginAssinaturausuarioConfig::getConfig('formato') === 'base64';
        $html = (string) preg_replace_callback(
            '#(src=)(["\'])[^"\']*?/plugins/assinaturausuario/front/imagem\.php\?t=([a-f0-9]{40})[^"\']*\2#i',
            function ($m) use ($embutir, $envio) {
                $novo = $embutir ? PluginAssinaturausuarioImagem::dataUri($m[3]) : null;
                return $m[1] . '"' . ($novo ?? PluginAssinaturausuarioImagem::url($m[3], $envio)) . '"';
            },
            $html
        );
        return '<div class="' . self::MARCA . '" data-assinaturausuario="' . (int) $usuario . '" style="margin-top:14px;padding-top:8px;border-top:1px solid #e0e0e0;">' . $html . '</div>';
    }

    // =====================================================================
    // Hooks
    // =====================================================================

    /** O pedido veio do formulário nativo do GLPI para esta classe? */
    private static function origemPermitida(CommonDBTM $item, string $tipo): bool
    {
        if ((isCommandLine() && !self::$assinaturausuarioSimularWeb) || (function_exists('isAPI') && isAPI()) || !empty($_SESSION['glpicronuserrunning'])) {
            return false;
        }
        $input = is_array($item->input) ? $item->input : [];
        if (!empty($input['_mailgate']) || !empty($input['_auto_import']) || !empty($input['_from_mailgate'])) {
            return false;
        }
        if (PluginAssinaturausuarioConfig::getConfig('outros_plugins') === '1') {
            return true;
        }
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }
        $caminho = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (str_contains($caminho, '/plugins/') || str_contains($caminho, '/marketplace/')) {
            return false;
        }
        foreach (PluginAssinaturausuarioConfig::TIPOS[$tipo][3] as $script) {
            if (preg_match('#/front/' . preg_quote($script, '#') . '$#', $caminho)) {
                // Uma única assinatura por requisição nativa (efeitos colaterais do GLPI ficam de fora)
                return !isset(self::$assinaturausuarioFeitos['req:' . $tipo]);
            }
        }
        return false;
    }

    /** pre_item_add: acrescenta a assinatura ao conteúdo, quando cabe */
    public static function aoAdicionar(CommonDBTM $item): void
    {
        try {
            if (!is_array($item->input) || !isset($item->input['content']) || !is_string($item->input['content'])) {
                return;
            }
            $chave = spl_object_id($item);
            if (isset(self::$assinaturausuarioFeitos[$chave])) {
                return;
            }
            $tipo = PluginAssinaturausuarioConfig::tipoDaClasse(get_class($item));
            $usuario = (int) Session::getLoginUserID();
            if ($tipo === null || $usuario <= 0 || !in_array($tipo, PluginAssinaturausuarioConfig::tiposAtivos(), true)) {
                return;
            }
            if (!empty($item->input['_assinaturausuario_ignorar']) || !PluginAssinaturausuarioConfig::perfilPermitido() || !self::origemPermitida($item, $tipo)) {
                return;
            }
            $conteudo = $item->input['content'];
            if (str_contains($conteudo, self::MARCA) || str_contains(html_entity_decode($conteudo), self::MARCA) || self::vazio($conteudo)) {
                return;
            }
            $assinatura = self::efetiva($usuario);
            if ($assinatura === null || !in_array($tipo, $assinatura['tipos'], true)) {
                return;
            }
            $item->input['content'] = rtrim($conteudo) . self::montar($assinatura['conteudo'], $usuario, self::entidadeDoItem($item), true);
            self::$assinaturausuarioFeitos[$chave] = true;
            self::$assinaturausuarioFeitos['req:' . $tipo] = true;
        } catch (\Throwable $e) {
            error_log('Plugin assinaturausuario: ' . $e->getMessage());
        }
    }

    /** Entidade do chamado/problema/mudança ao qual o item pertence */
    private static function entidadeDoItem(CommonDBTM $item): int
    {
        $input = $item->input;
        $tipoPai = (string) ($input['itemtype'] ?? '');
        $idPai = (int) ($input['items_id'] ?? 0);
        if ($item instanceof CommonITILTask) {
            $tipoPai = $item::getItilObjectItemType();
            $idPai = (int) ($input[getForeignKeyFieldForItemType($tipoPai)] ?? 0);
        }
        if ($tipoPai !== '' && $idPai > 0 && is_a($tipoPai, CommonITILObject::class, true)) {
            $pai = new $tipoPai();
            if ($pai->getFromDB($idPai)) {
                return (int) $pai->fields['entities_id'];
            }
        }
        return -1;
    }

    /** post_item_form: aviso abaixo do editor (novo acompanhamento, solução ou tarefa) */
    public static function aoExibirFormulario(array $params): void
    {
        try {
            $item = $params['item'] ?? null;
            if (!$item instanceof CommonDBTM || !$item->isNewItem()) {
                return;
            }
            $tipo = PluginAssinaturausuarioConfig::tipoDaClasse(get_class($item));
            $usuario = (int) Session::getLoginUserID();
            if ($tipo === null || $usuario <= 0 || !in_array($tipo, PluginAssinaturausuarioConfig::tiposAtivos(), true) || !PluginAssinaturausuarioConfig::perfilPermitido()) {
                return;
            }
            $assinatura = self::efetiva($usuario);
            if ($assinatura === null || !in_array($tipo, $assinatura['tipos'], true)) {
                return;
            }
            $pai = $params['options']['item'] ?? ($params['options']['parent'] ?? null);
            $entidade = $pai instanceof CommonDBTM && isset($pai->fields['entities_id']) ? (int) $pai->fields['entities_id'] : -1;
            $previa = \Glpi\RichText\RichText::getSafeHtml(self::montar($assinatura['conteudo'], $usuario, $entidade, false));
            $e = [PluginAssinaturausuarioConfig::class, 'e'];
            global $CFG_GLPI;
            echo '<div class="assinaturausuario-aviso">'
                . '<details><summary><i class="ti ti-signature"></i><span>' . ($assinatura['origem'] === 'modelo' ? 'A assinatura padrão' : 'Sua assinatura') . ' será adicionada ao enviar</span></summary>'
                . '<div class="assinaturausuario-previa-mini">' . $previa . '</div></details>'
                . '<label class="assinaturausuario-ignorar"><input type="checkbox" class="assinaturausuario-check" name="_assinaturausuario_ignorar" value="1"> Não assinar esta mensagem</label>'
                . '<a class="assinaturausuario-editar" href="' . $e($CFG_GLPI['root_doc'] . '/front/preference.php?forcetab=PluginAssinaturausuarioPreferencia$1') . '" target="_blank" title="Alterar minha assinatura"><i class="ti ti-pencil"></i></a>'
                . '</div>';
        } catch (\Throwable $e) {
            error_log('Plugin assinaturausuario: ' . $e->getMessage());
        }
    }
}
