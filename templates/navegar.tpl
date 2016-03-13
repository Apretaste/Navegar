<h1>{$title}</h1>

{if $isLarge}
	<p><font color="red"><small>Este art&iacute;culo es demasiado grande para ser enviado por email. Apretaste ha eliminado las im&aacute;genes para hacer posible que personas con conexiones limitadas lo reciban.</small></font></p>
{elseif $image}
	<center>
		{img width="300" src="{$image}" alt="Foto de {$title}"}
		{space10}
	</center>
{/if}

{$body}

{if $isLarge}
	{space15}
	<p><font color="red"><small>Este art&iacute;culo es demasiado grande. Apretaste ha cortado el resto del texto para hacer posible que usuarios con conexiones limitadas lo reciban. Sentimos mucho esta limitaci&oacute;n de nuestro servicio :-(</small></font></p>
{/if}