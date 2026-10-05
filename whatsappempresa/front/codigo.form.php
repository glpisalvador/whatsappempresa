<?php
/**
 * Formulario nativo do codigo de acesso
 */

Session::checkRight('config', READ);

$codigo = new PluginWhatsappempresaCodigo();

if (isset($_POST['add'])) {
   $codigo->check(-1, CREATE, $_POST);
   $id = $codigo->add($_POST);
   if ($id && $_SESSION['glpibackcreated']) {
      Html::redirect($codigo->getLinkURL());
   }
   Html::back();
} elseif (isset($_POST['update'])) {
   $codigo->check((int)$_POST['id'], UPDATE);
   $codigo->update($_POST);
   Html::back();
} elseif (isset($_POST['delete'])) {
   $codigo->check((int)$_POST['id'], DELETE);
   $codigo->delete($_POST);
   $codigo->redirectToList();
} elseif (isset($_POST['restore'])) {
   $codigo->check((int)$_POST['id'], DELETE);
   $codigo->restore($_POST);
   $codigo->redirectToList();
} elseif (isset($_POST['purge'])) {
   $codigo->check((int)$_POST['id'], PURGE);
   $codigo->delete($_POST, true);
   $codigo->redirectToList();
}

Html::header(PluginWhatsappempresaCodigo::getTypeName(2), '', 'whatsapp', 'pluginwhatsappempresacodigo');
$codigo->display(['id' => (int)($_GET['id'] ?? 0)]);
Html::footer();
