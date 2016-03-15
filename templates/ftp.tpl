<h1>Accediendo al servidor de archivos</h1>
<p>A continuaci&oacute;n te listamos el contenido de la direcci&oacute;n FTP que solicitaste:<br/><br/> <b>{$url}</b></p>
<ul>
{foreach item=item from=$contents}
	<li>{link href="NAVEGAR {$base_url}/{$item}" caption="{$item}"}</li>
{/foreach}
</ul>