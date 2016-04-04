<h1>Bienvenido a navegar en Internet</h1>
<p align="justify">Este servicio le permitir&aacute; <b>buscar y visitar direcciones en Internet mediante el email</b>, 
d&iacute;gase, sitios webs, descargar archivos peque&ntilde;os, ver canales de noticias ({link href="WIKIPEDIA RSS" caption="RSS"}, 
{link href="WIKIPEDIA Atom" caption="Atom"}), acceder a servidores 
de archivo {link href="WIKIPEDIA FTP" caption="FTP"}, ver im&aacute;genes, entre otros. Los enlaces a otras direcciones en 
Internet podr&aacute; seguirlos haciendo clic en ellos cuando se le muestren y enviando los correos preparados con ese fin.</p>

<p align="justify">Las <b>im&aacute;genes de los sitios webs se le mostrar&aacute;n como enlaces</b> para 
obtenerlas en un correo aparte. Esas im&aacute;genes <b>ser&aacute;n reducidas</b> para que le puedan ser 
entregadas con facilidad.</p>

<p align="justify">Los dem&aacute;s tipos de <b>archivos que solicite le ser&aacute;n entregados como 
adjuntos</b>, siempre y cuando no excedan los <b></b>{$max_attachment_size} kilobytes</b> despu&eacute;s 
de comprimirlos en <b>formato ZIP</b>.</p>

<p align="justify">Podr&aacute;s <b>interactuar con algunos formularios en la web y posiblemente mantener 
sesiones activas en sitios</b> en los cuales debes registrarte para poder visitarlos. En esta funcionalidad estamos trabajando 
para mejorarla.</p>

<h2>C&oacute;mo navegar?</h2>
<p align="justify">Para utilizar este servicio debe enviar un correo a Apretaste y en el asunto escribir NAVEGAR seguida 
de dos posibles datos:</p>
<ol>
	<li><b>una direcci&oacute;n en Internet</b>, d&iacute;gase un sitio web, canal de noticias, servidor de archivos, imagen u otro archivo, por ejemplo:
		<ul>
			<li>Para: {APRETASTE_EMAIL}</li>
			<li>Asunto: <b>NAVEGAR http://revolico.com/computadoras</b></li>
		</ul>
		<p>Apretaste acceder&aacute; a ese sitio por usted y se lo traer&aacute; a su email para que 
		pueda navegar en &eacute;l.</p>
		<center>{button href="NAVEGAR http://revolico.com/computadoras" caption="Probar visitar" color="blue"}</center>
		{space10}
	</li>
	
	<li><b>una frase para buscar en Internet</b>, por ejemplo:
		<ul>
			<li>Para: {APRETASTE_EMAIL}</li>
			<li>Asunto: <b>NAVEGAR cuba</b></li>
		</ul>
		<p>Apretaste buscar&aacute; en la web los sitios que hablen de "cuba".</p>
		<center>{button href="NAVEGAR cuba" caption="Probar buscar" color="blue"}</center>
	</li>
</ol> 

<h2>Los subservicios</h2>
<p>Actualmente este servicio contiene 2 subservicios:</p>
<p align="justify"><b>NAVEGAR MOVIL:</b> Agregando la palabra MOVIL al principio podr&aacute;s navegar en 
Internet identific&aacute;ndote como un dispositivo m&oacute;vil. 
Esto es &uacute;til para obtener p&aacute;ginas en formato reducido, t&iacute;pico en estos dispositivos.</p> 
<center>{button href="NAVEGAR MOVIL facebook.com" caption="Probar m&oacute;vil"}</center>

<p align="justify"><b>NAVEGAR NOTICIAS:</b> Agregando la palabra NOTICIAS al principio podras buscar noticias en internet.</p> 
<center>{button href="NAVEGAR NOTICIAS obama" caption="Probar noticias"}</center>
{space10}
<h2>Algunos sitios que pueden ser de tu inter&eacute;s</h2>
<p>Si no sabes por donde empezar te listamos aqu&iacute; algunos sitios populares que pueden 
ser de tu inter&eacute;s:</p>
<ul>
	<li>{link href="NAVEGAR http://carlostercero.ca/" caption ="carlostercero.ca - Tienda Carlos III"}</li>
	<li>{link href="NAVEGAR http://lyrics.com/" caption ="lyrics.com - Letras de m&uacute;sica"}</li>
	<li>{link href="NAVEGAR http://revolico.com/" caption ="revolico.com - Revolico"}</li>
	<li>{link href="NAVEGAR http://wikia.com" caption ="wikia.com - Enciclopedias"}</li>
	<li>{link href="NAVEGAR http://es.wikipedia.org/" caption ="Wikipedia, la enciclopedia libre"}</li>
	<li>{link href="NAVEGAR http://blogspot.com" caption ="blogspot.com - Blogs"}</li>
	<li>{link href="NAVEGAR http://wordpress.com" caption ="wordpress.com - Blogs"}</li>
	<li>{link href="NAVEGAR http://20minutos.es" caption ="20minutos.es - Blogs 20 Minutos"}</li>
	<li>{link href="NAVEGAR http://cnn.com" caption ="cnn.com - CNN Noticias"}</li>
	<li>{link href="NAVEGAR http://feeds.bbci.co.uk/news/england/rss.xml" caption ="Canal BBC Noticias"}</li>
	<li>{link href="NAVEGAR http://Espn.go.com" caption ="Espn.go.com - Noticias deportivas"}</li>
	<li>{link href="NAVEGAR ftp://ftp.4d.com/" caption="ftp.4d.com - Ejemplo servidor FTP"}</li>	
</ul>
{if $visits !== false}
	<h2>Sitios m&aacute;s visitados</h2>
	<p>Estos son los sitios m&aacute;s visitados por los usuarios de este servicio:</p>
	<ul>
	
	{foreach item=item from=$visits}
		<li>{link href="NAVEGAR {$item->site}" caption="{$item->site}"}</li>
	{/foreach}
{/if}
</ul>