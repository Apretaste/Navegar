<h1>Archivo descargado</h1>
<p>Te adjuntamos el archivo descargado desde la direcci&oacute;n FTP que solicitaste. <br/><br/><b>{$url} 
{if $size > 0}
({$size} Kb)
{/if}</b></p>
{if $zipped}
<p>El archivo fue comprimido en formato {link href="WIKIPEDIA ZIP" caption="ZIP"} para hac&eacute;rtelo llegar con facilidad.</p>
{/if}
<center>{button href="NAVEGAR {$url}" caption="Descargar otra vez"}</center>