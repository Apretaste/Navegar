<h1>Accediendo al servidor de archivos</h1>
<p>
	A continuaci&oacute;n te listamos el contenido de la direcci&oacute;n FTP que solicitaste:<br /> 
	<br /> <b>{$url}</b>
</p>
<table width="100%">
	{foreach item=item from=$contents}
	<tr>
		<td style="padding: 5px;border-bottom: 1px solid gray;">{link href="NAVEGAR {$base_url}/{$item['name']}" caption="{$item['name']}"}</td>
		<td style="padding: 5px;border-bottom: 1px solid gray;" align="right">{if $item['type'] != 'd'} {$item['size']} {else} --{/if}</td>
		<td style="padding: 5px;border-bottom: 1px solid gray;"><small>{$item['day']} {$item['month']} {$item['year']}</small></td>
	</tr>
	{/foreach}
</table>