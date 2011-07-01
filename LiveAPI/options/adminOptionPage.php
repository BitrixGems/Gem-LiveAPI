<?php
if (function_exists('mb_internal_encoding'))
	mb_internal_encoding('ISO-8859-1');

define('DATA_FILE',dirname(__FILE__).'/live_api.data.php');

$offset = intval($_REQUEST['offset']);
if ($_REQUEST['scan'])
{
	$dbtype = 'mysql';
	$path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules';
	if (!($dir = opendir($path)))
		die('Cannot read '.$path);

	$gotIt = false;
	while(false !== ($file = readdir($dir)))
	{
		if ($file == '.' || $file == '..' || !is_dir($path.'/'.$file))
			continue;

		$next_file = $file;
		if ($gotIt)
			break;

		$gotIt = !$_REQUEST['next_file'] || $_REQUEST['next_file'] == $file;
		$last_file = $file;
	}
	closedir($dir);

	if ($gotIt)
	{
		$ar = ScanModule($last_file);

		$f = fopen(DATA_FILE,$_REQUEST['next_file']?'ab':'wb');
		fwrite($f, '<'.'? $DATA[\''.$last_file.'\'] = \''.str_replace("'","\'",serialize($ar)).'\'; ?'.'>'."\n");
		fclose($f);

		if ($next_file != $last_file)
			die('<div>Сканирование модуля: ' . $last_file . '</div> <script>document.location="?gem=LiveAPI&scan=Y&next_file='.htmlspecialchars($next_file).'";</script>');
		else
			echo '<div>Сканирование завершено</div>';
	}
	else
		die('Logical error');
}
else
{
	$bNeedToRescan = true;
	if (file_exists(DATA_FILE))
	{
		//require($_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/main/include/prolog_before.php');
		$utime = COption::GetOptionString("main", "update_system_update", 0);
		$bNeedToRescan = MakeTimeStamp($utime) > filemtime(DATA_FILE);
	}
	//header("Content-type:text/html; charset=windows-1251");
	if ($bNeedToRescan)
		echo '<div style="color:red">Были установлены обновления после сканирования модулей! Требуется выполнить повторное сканирование.</div>';
}

echo '<div><input type=button value="Сканировать модули" onclick="document.location=\'?gem=LiveAPI&scan=Y\'"></div>';

if (file_exists(DATA_FILE))
{
	include(DATA_FILE);
	$arModules = array_keys($DATA);
	sort($arModules);
	echo 'Выберите модуль: <select onchange="document.location=\'?gem=LiveAPI&module=\'+this.value"><option></option>';
	foreach($arModules as $k)
		echo '<option value="'.$k.'" '.($k== $_REQUEST['module'] ? 'selected' : '').'>'.$k.'</option>';
	echo '</select>';

	if (isset($DATA[$_REQUEST['module']]))
	{
		$arClasses = array();
		list($arRes,$arEvt,$arConst) = unserialize($DATA[$_REQUEST['module']]);
		$ar = array_keys($arRes);
		foreach($ar as $str)
		{
			if ($class = ($p = strpos($str,'::')) ? substr($str,0,$p) : false)
				$arClasses[$class] = 1;
		}

		if (count($arClasses))
		{
			echo ' класс: <select onchange="document.location=\'?gem=LiveAPI&module='.$_REQUEST['module'].'&class=\'+this.value"><option></option>';
			foreach($arClasses as $k=>$v)
				echo '<option value="'.$k.'" '.($k== $_REQUEST['class'] ? 'selected' : '').'>'.$k.'</option>';
			echo '</select>';
		}
	}

	echo '<form method="get">Искать метод или класс: <input name=search value="'.htmlspecialchars($_REQUEST['search']).'" size=30> <input type=submit value=Поиск><input type="hidden" name="gem" value="LiveAPI" /></form>';
	if ($_REQUEST['search'])
	{
		echo '<table border=1 cellpadding=4 cellspacing=0>';
		echo
		'<tr align=center bgcolor="#CCCCCC">'.
			"<td><b>Модуль</td>".
			"<td><b>Метод</td>".
		'</tr>';
		foreach($DATA as $module=>$sar)
		{
			$ar = unserialize($sar);
			list($arRes,$arEvt,$arConst) = $ar;
			foreach($arRes as $k=>$v)
			{
				if (stripos($k,$_REQUEST['search']) !== false)
					echo '<tr><td><a href="?gem=LiveAPI&module='.$module.'">'.$module.'</a></td><td>'.colorize($k,$v).'</td></tr>';
			}
		}
		echo '</table>';
	}
	elseif (isset($DATA[$_REQUEST['module']]))
		Show($_REQUEST['module'],unserialize($DATA[$_REQUEST['module']]),$_REQUEST['class']);

	if ($_REQUEST['file']){
		if (!($f = fopen($_SERVER['DOCUMENT_ROOT'].$_REQUEST['file'], 'rb')))
			die('Cannot read '.htmlspecialchars($_REQUEST['file']));
		fseek($f, $offset);

		$str = '';
		$open = $close = 0;
		while(false !== ($l = fgets($f)))
		{
			$open += substr_count($l, '{');
			$close += substr_count($l, '}');

			$str .= $l;

			if ($open > 0 && $close >= $open)
				break;
		}
		fclose($f);

		echo Beautiful($str);

	}

}


#########################################
function ScanModule($module)
{
	global $dbtype;
	$arRes = array();
	$arEvt = array();
	$arConst = array();

	$path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$module.'/include.php';
	$arRes = ParseFile($path, $arEvt, $arConst);

	if (file_exists($path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$module.'/tools.php'))
	{
		if (false !== ($ar = ParseFile($path, $arEvt, $arConst)))
			$arRes = array_merge($arRes, $ar);
	}

	if (file_exists($path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$module.'/filter_tools.php'))
	{
		if (false !== ($ar = ParseFile($path, $arEvt, $arConst)))
			$arRes = array_merge($arRes, $ar);
	}

	foreach(array('general',$dbtype) as $folder)
	{
		$path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$module.'/classes/'.$folder;

		if (!file_exists($path))
			$path = $_SERVER['DOCUMENT_ROOT'].'/bitrix/modules/'.$module.'/'.$folder;

		if (!file_exists($path))
			continue;

		if (!($dir = opendir($path)))
			die('Cannot read '.$path);

		while(false !== ($file = readdir($dir)))
		{
			if ($file == '.' || $file == '..' || is_dir($path.'/'.$file) || end(explode('.',$file)) != 'php')
				continue;

			if (!is_array($ar = ParseFile($path.'/'.$file, $arEvt, $arConst)))
				continue;

			$arRes = array_merge($arRes, $ar);
		}
	}
	ksort($arRes);
	ksort($arEvt);
	ksort($arConst);
	return array($arRes,$arEvt,$arConst);
}

function Show($module, $ar, $class)
{
	list($arRes,$arEvt,$arConst) = $ar;
	if (!$class && count($arEvt))
	{
		echo '<h2>События модуля '.htmlspecialchars($module).'</h2>';
		echo '<table border=1 cellpadding=4 cellspacing=0>';
			echo
			'<tr align=center bgcolor="#CCCCCC">'.
				"<td><b>Событие</td>".
				"<td><b>Вызывается</td>".
			'</tr>';

		foreach($arEvt as $evt => $func)
		{
			$ar = $arRes[$func];
			$link = "?gem=LiveAPI&file=$ar[FILE]&offset=$ar[OFFSET]&name=$func&line=$ar[LINE]&highlight=".$evt.'#'.$evt;
			echo
			'<tr>'.
				"<td valign=top class=code><a href='$link' >$evt</td>".
				"<td valign=top class=code>$func</td>".
			'</tr>';
		}
		echo '</table>';
	}

	if (!$class && count($arConst))
	{
		echo '<h2>Константы модуля '.htmlspecialchars($module).'</h2>';
		echo '<table border=1 cellpadding=4 cellspacing=0>';
			echo
			'<tr align=center bgcolor="#CCCCCC">'.
				"<td><b>Константа</td>".
				"<td><b>Проверяется</td>".
			'</tr>';

		foreach($arConst as $const => $func)
		{
			$ar = $arRes[$func];
			$link = "?gem=LiveAPI&file=$ar[FILE]&offset=$ar[OFFSET]&name=$func&line=$ar[LINE]&highlight=".$const.'#'.$const;
			echo
			'<tr>'.
				"<td valign=top class=code><a href='$link' >$const</td>".
				"<td valign=top class=code>$func</td>".
			'</tr>';
		}
		echo '</table>';
	}

	if (count($arRes))
	{

		echo '<h2>Список функций и методов модуля '.htmlspecialchars($module).'</h2>';
		echo '<table border=1 cellpadding=4 cellspacing=0>';
			echo
			'<tr align=center bgcolor="#CCCCCC">'.
				"<td><b>Метод</td>".
			'</tr>';

		foreach($arRes as $func => $ar)
		{
			if ($str = colorize($func,$ar,$class,$module))
				echo
				'<tr>'.
					"<td valign=top class=code>".$str."</td>".
				'</tr>';
		}
		echo '</table>';
	}
}

function colorize($func,$ar,$class = false, $module=false)
{
	$link = "?gem=LiveAPI&file=$ar[FILE]&offset=$ar[OFFSET]&name=$func&line=$ar[LINE]";
	if ($c = strpos($func, "::"))
	{
		if ($class && substr($func,0,$c) != $class)
			return;
		$func = '<a href="?gem=LiveAPI&module='.$module.'&class='.substr($func,0,$c).'" class=class>'.substr($func,0,$c).'</span>::<a href="'.$link.'" ><span class=method>'.substr($func,$c+2).'</span></a>';
	}
	else
	{
		if ($class)
			return;
		$func = '<a href="'.$link.'" ><span class=method>'.$func.'</span></a>';
	}

	$args = preg_replace('#(\$[a-z0-9_]+)#i','<span class=var>\\1</span>',htmlspecialchars($ar['ARGS']));
	return $func.'('.$args.')';
}

function ParseFile($file, &$arEvt, &$arConst)
{
	$f = fopen($file, 'rb');
	if ($f === false)
		return false;
	$arRes = array();

	$len = strlen($_SERVER['DOCUMENT_ROOT']);
	$i = 0;
	$offset = 0;
	$curClass = '';
	$curFunc = '';
	$js = false;
	while(false !== ($l = fgets($f)))
	{
		$i++;
		if (preg_match('#<script>#i',$l))
			$js = true;
		if (preg_match('#</script>#i',$l))
			$js = false;

		if (!$js)
		{
			if (preg_match('#^\s?class ([a-z0-9_]+)#i', $l, $regs))
			{
				$curClass = preg_replace('#^CAll#i','C',$regs[1]);
				$open = $close = 0;
			}
			elseif (preg_match('#^([a-z 	]*)function ([a-z0-9_]+) ?\((.*)\)#i', $l, $regs))
			{
				$curFunc = $func = ($curClass ? $curClass.'::' : '').$regs[2];
				$args = $regs[3];
				$arRes[$func] = array(
					'FILE' => substr($file,$len),
					'LINE' => $i,
					'OFFSET' => $offset,
					'ARGS' => $args,
				);
			}
			elseif (preg_match('#^([a-z 	]*)function ([a-z0-9_]+) ?\(#i', $l, $regs))
			{
				$curFunc = $func = ($curClass ? $curClass.'::' : '').$regs[2];
				$args = 'N/A';
				$arRes[$func] = array(
					'FILE' => substr($file,$len),
					'LINE' => $i,
					'OFFSET' => $offset,
					'ARGS' => $args,
				);
			}
			elseif (preg_match('#GetModuleEvents\([^,]+,["\' ]*([\$a-z0-9_]+)#i', $l, $regs))
			{
				$event = $regs[1];
				$arEvt[$event] = $curFunc;
			}
			elseif (preg_match('#ExecuteEvents\([\'"]?([\$a-z0-9_]+)#i', $l, $regs))
			{
				$event = $regs[1];
				$arEvt[$event] = $curFunc;
			}

			if ($curFunc && preg_match('#defined\(["\']([a-z_]+)["\']\)#i', $l, $regs))
				$arConst[$regs[1]] = $curFunc;

			if ($curClass)
			{
				$open += substr_count($l, '{');
				$close += substr_count($l, '}');
			}

			if ($open > 0 && $close >= $open)
				$curClass = '';
		}
		$offset += strlen($l);
	}
	fclose($f);
	return $arRes;
}

function Beautiful($html)
{
	global $raw;
	$raw = $html;
	$html = highlight_string("<?"."php \n//	$_REQUEST[name]\n//	$_REQUEST[file]:$_REQUEST[line]\n\n".$html,true);

	if (file_exists($file = dirname(__FILE__).'/live_api.data.php'))
	{
		$class = ($p = strpos($_REQUEST['name'],'::')) ? substr($_REQUEST['name'],0,$p) : false;
		include($file);
		foreach($DATA as $module=>$ar)
		{
			list($arRes,$arEvt) = unserialize($ar);
			if (is_array($arRes))
				foreach($arRes as $k=>$v)
				{
					if ($k == $_REQUEST['name'])
						continue;

					$html = GetLink($k, $v, $html);
					if ($class)
						$html = GetLink($k, $v, $html,$class.'::','$this->');

					if ($module == 'main')
					{
						$html = GetLink($k, $v, $html,'CUser::', '$USER->');
						$html = GetLink($k, $v, $html,'CMain::', '$APPLICATION->');
						$html = GetLink($k, $v, $html,'CDatabase::', '$DB->');
					}

					$curClass = ($p0 = strpos($k,'::')) ? substr($k,0,$p0) : false;
					if ($curClass && $lastClass != $curClass)
					{
						$lastClass = $curClass;
						$html = preg_replace('#(new&nbsp;</span><span[^>]*>)'.$curClass.'#i',"$1".'<a href="?gem=LiveAPI&module='.$module.'&class='.htmlspecialchars($curClass).'">'.$curClass.'</a>',$html);
					}
				}
		}
	}

	if ($_REQUEST['highlight'])
		$html = str_replace($_REQUEST['highlight'],'<a name="'.htmlspecialchars($_REQUEST['highlight']).'"></a><span style="background:#FFFF00">'.$_REQUEST['highlight'].'</span>',$html);

	if ($class)
	{
		$file = str_replace('\\','/',$_REQUEST['file']);
		if (preg_match('#^/bitrix/modules/([^/]+)/#',$file,$regs))
		{
			$module = $regs[1];
			$html = str_replace($_REQUEST['name'],'<a href="?gem=LiveAPI&module='.$module.'&class='.$class.'">'.$class.'</a>'.substr($_REQUEST['name'],$p),$html);
		}
		$html = str_ireplace('public&nbsp;','<span style="color:#933;font-weight:bold">public</span>&nbsp;',$html);
		$html = str_ireplace('private&nbsp;','<span style="color:#933;font-weight:bold">private</span>&nbsp;',$html);
		$html = str_ireplace('protected&nbsp;','<span style="color:#933;font-weight:bold">protected</span>&nbsp;',$html);
		$html = str_ireplace('static&nbsp;','<span style="color:#333;font-weight:bold">static</span>&nbsp;',$html);
	}

	return $html;
}

function GetLink($code, $v, $html, $from = false, $to = false)
{
	global $raw;

	$s_code = $code;
	if ($from)
	{
		if (false === strpos($code,$from))
			return $html;
		$s_code = str_replace($from,$to, $code);
	}
	if (false === strpos($raw,$s_code))
		return $html;

	$p_code = str_replace('::','</span><span[^>]+>::</span><span[^>]+>',$s_code);
	$p_code = str_replace('->','</span><span[^>]+>-&gt;</span><span[^>]+>',$p_code);
	$p_code = str_replace('$','\$',$p_code);

	return preg_replace(
		'#<span[^>]+>'.$p_code.'</span>#i',
		'<a href="?gem=LiveAPI&file='.$v['FILE'].'&offset='.$v['OFFSET'].'&name='.$code.'&line='.$v['LINE'].'">'.$s_code.'</a>',
		$html
	);
}
?>
<style>
	.divx {
		border:1px solid #CCC;
		margin:2px;
	}

	td {
		font-family:Verdana,Tahoma,Arial;
	}

	.code {
		font-family:Courier;
	}

	.class {
		color:#993;
		font-weight:bold;
	}

	.method {
		color:#66F;
	}

	.var {
		color:#363;
	}
</style>
