<p>Se te muestra la direcci&oacute;n web que haz solicitado: <b>{$url}</b>.  Puedes obtenerla en formato PDF {link caption="con" href="WEB FULL {$url}"} o {link caption="sin im&aacute;genes" href="WEB FULL {$url}"}.
La p&aacute;gina que se te muestra tiene un tama&ntilde;o aproximado de <b>{$body_length}Kb</b>.
</p>
<h1>{$title}</h1>
<fieldset style="min-width:450px;overflow:auto;">
{$body}
</fieldset>
<hr/>
{if !empty($resources) }
	<h2>Recursos de la p&aacute;gina</h2>
	<ul>
	{foreach item=item from=$resources}
		<li>{link href="NAVEGAR {$item}" caption="{$item|truncate:100:"...":true}"}</li>
	{/foreach}
	</ul>
{/if}