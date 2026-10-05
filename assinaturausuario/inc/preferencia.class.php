<?php

/**
 * Plugin Assinatura de Usuário - aba "Assinatura" nas Preferências (a própria) e no cadastro do
 * usuário (para quem pode alterar usuários)
 */
class PluginAssinaturausuarioPreferencia extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Assinatura';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($withtemplate) {
            return '';
        }
        if ($item instanceof Preference && PluginAssinaturausuarioConfig::perfilPermitido()) {
            return self::createTabEntry('Assinatura', 0, null, 'ti ti-signature');
        }
        if ($item instanceof User && !$item->isNewItem() && self::podeEditar((int) $item->getID())) {
            return self::createTabEntry('Assinatura', 0, null, 'ti ti-signature');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Preference) {
            self::mostrar((int) Session::getLoginUserID());
        } elseif ($item instanceof User) {
            self::mostrar((int) $item->getID());
        }
        return true;
    }

    /** A própria assinatura, ou a de outro usuário para quem pode alterar usuários */
    public static function podeEditar(int $usuario): bool
    {
        $atual = (int) Session::getLoginUserID();
        if ($usuario <= 0 || $atual <= 0) {
            return false;
        }
        if ($usuario === $atual) {
            return true;
        }
        $u = new User();
        return Session::haveRight('user', UPDATE) && $u->getFromDB($usuario) && $u->canUpdateItem();
    }

    public static function mostrar(int $usuario): void
    {
        global $CFG_GLPI;
        $C = PluginAssinaturausuarioConfig::class;
        $A = PluginAssinaturausuarioAssinatura::class;
        $e = [$C, 'e'];
        if (!self::podeEditar($usuario)) {
            return;
        }
        $propria = $usuario === (int) Session::getLoginUserID();
        $registro = $A::doUsuario($usuario);
        $modelo = (string) $C::getConfig('modelo');
        $temModelo = !$A::vazio($modelo);
        $conteudo = $registro !== null ? (string) $registro['conteudo'] : '';
        $ativo = $registro === null || !empty($registro['is_active']);
        $tiposAtivos = $C::tiposAtivos();
        $tiposPessoa = $registro !== null ? $registro['tipos'] : array_keys($C::TIPOS);

        echo '<div class="assinaturausuario-pagina" data-assinaturausuario-pref data-ajax="' . $e($C::url('ajax.php')) . '" data-usuario="' . $usuario . '" data-token="' . $e($C::tokenCsrf()) . '">';
        if (!$propria) {
            echo '<div class="assinaturausuario-alerta assinaturausuario-alerta-info"><i class="ti ti-info-circle"></i><span>Você está alterando a assinatura de <strong>' . $e(getUserName($usuario)) . '</strong>.</span></div>';
        }
        if (!$tiposAtivos) {
            echo '<div class="assinaturausuario-alerta assinaturausuario-alerta-aviso"><i class="ti ti-alert-triangle"></i><span>A assinatura está desligada para todos os tipos na configuração do plugin.</span></div>';
        }
        if ($registro === null && $C::getConfig('usar_modelo') === '1' && $temModelo) {
            echo '<div class="assinaturausuario-alerta assinaturausuario-alerta-info"><i class="ti ti-info-circle"></i><span>Enquanto você não salvar uma assinatura própria, a <strong>assinatura padrão</strong> da empresa é usada.</span></div>';
        }

        echo '<form method="post" action="' . $e($C::url('assinatura.form.php')) . '" class="assinaturausuario-form">'
            . '<input type="hidden" name="users_id" value="' . $usuario . '"><input type="hidden" name="save_action" value="salvar">';
        echo '<div class="assinaturausuario-grade">';

        // ------------------------------------------------ editor
        echo '<div class="card assinaturausuario-card"><div class="card-header"><h5><i class="ti ti-signature"></i> ' . ($propria ? 'Minha assinatura' : 'Assinatura') . '</h5></div><div class="card-body">';
        echo '<div class="assinaturausuario-linha">'
            . '<div class="form-check form-switch assinaturausuario-switch"><input type="hidden" name="is_active" value="0">'
            . '<input class="form-check-input" type="checkbox" id="assinaturausuario-ativo" name="is_active" value="1"' . ($ativo ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="assinaturausuario-ativo">Assinar automaticamente</label></div>';
        echo '<div class="assinaturausuario-tipos"><span class="assinaturausuario-rotulo">Em:</span>';
        foreach ($C::TIPOS as $t => [$rotulo, $icone]) {
            $ligado = in_array($t, $tiposAtivos, true);
            echo '<label class="assinaturausuario-opcao' . ($ligado ? '' : ' desligada') . '"' . ($ligado ? '' : ' title="Desligado na configuração do plugin"') . '>'
                . '<input type="checkbox" class="assinaturausuario-check" name="tipos[]" value="' . $e($t) . '"' . (in_array($t, $tiposPessoa, true) ? ' checked' : '') . ($ligado ? '' : ' disabled') . '>'
                . '<i class="' . $e($icone) . '"></i> ' . $e($rotulo) . '</label>';
        }
        echo '<input type="hidden" name="tipos[]" value=""></div></div>';

        echo '<div class="assinaturausuario-variaveis"><span class="assinaturausuario-rotulo">Inserir:</span>';
        foreach ($C::VARIAVEIS as $var => $descricao) {
            echo '<button type="button" class="assinaturausuario-variavel" data-assinaturausuario-variavel="' . $e($var) . '" title="' . $e($descricao) . '">' . $e($var) . '</button>';
        }
        if ($temModelo) {
            echo '<button type="button" class="btn btn-sm btn-ghost-secondary assinaturausuario-usar-modelo" data-assinaturausuario-modelo><i class="ti ti-template"></i><span>Usar assinatura padrão</span></button>'
                . '<template data-assinaturausuario-modelo-html>' . \Glpi\RichText\RichText::getSafeHtml($modelo) . '</template>';
        }
        echo '</div>';

        Html::textarea([
            'name'            => 'assinatura',
            'editor_id'       => 'assinaturausuario_editor',
            'value'           => $conteudo,
            'enable_richtext' => true,
            'enable_images'   => true,
            'cols'            => 100,
            'rows'            => 10,
        ]);
        echo '<p class="assinaturausuario-explicacao"><i class="ti ti-info-circle"></i><span>Cole ou arraste imagens (logotipo, selos) direto no editor. Ao salvar, elas são reduzidas para até '
            . (int) $C::largura() . ' px e guardadas pelo plugin, para aparecerem sempre como imagem nos chamados e nos e-mails.</span></p>';
        echo '</div></div>';

        // ------------------------------------------------ prévia
        $previa = $conteudo !== '' ? \Glpi\RichText\RichText::getSafeHtml($A::montar($conteudo, $usuario, -1, false)) : '';
        echo '<div class="card assinaturausuario-card"><div class="card-header"><h5><i class="ti ti-eye"></i> Prévia num acompanhamento</h5></div><div class="card-body">'
            . '<div class="assinaturausuario-previa"><div class="assinaturausuario-previa-texto">Mensagem do acompanhamento...</div>'
            . '<div data-assinaturausuario-previa>' . ($previa !== '' ? $previa : '<span class="assinaturausuario-pequeno">Sem assinatura.</span>') . '</div></div>'
            . '<p class="assinaturausuario-explicacao"><i class="ti ti-info-circle"></i><span>As variáveis são trocadas pelos seus dados no momento do envio. A assinatura entra uma vez, só em itens novos enviados pela linha do tempo; editar um acompanhamento não a repete. Para não assinar uma mensagem, marque "Não assinar esta mensagem" abaixo do editor.</span></p>'
            . '</div></div>';
        echo '</div>';

        echo '<div class="assinaturausuario-rodape-form">';
        if ($registro !== null) {
            echo '<button type="submit" name="remover" value="1" class="btn btn-sm btn-ghost-danger" data-assinaturausuario-confirmar="Clique de novo para remover"><i class="ti ti-trash"></i><span>Remover assinatura</span></button>';
        }
        echo '<button type="submit" class="btn btn-sm assinaturausuario-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar assinatura</span></button></div>';
        echo Html::closeForm(false);
        echo '</div>';
    }
}
