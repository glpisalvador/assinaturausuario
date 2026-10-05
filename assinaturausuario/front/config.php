<?php

/**
 * Plugin Assinatura de Usuário - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginAssinaturausuarioConfig::url('config.form.php'));
