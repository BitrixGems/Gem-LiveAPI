<?php
/**
 * Подключаем jQuery :)
 *
 * @author		Vladimir Savenkov <iVariable@gmail.com>
 *
 */
class BitrixGem_LiveAPI extends BaseBitrixGem{

	protected $aGemInfo = array(
		'GEM'			=> 'LiveAPI',
		'AUTHOR'		=> 'Шаромов Денис',
		'AUTHOR_LINK'	=> 'http://dev.1c-bitrix.ru/community/webdev/group/78/blog/1991/',
		'DATE'			=> '06.02.2011',
		'VERSION'		=> '0.1',
		'NAME' 			=> 'LiveAPI',
		'DESCRIPTION' 	=> "Гем, предоставляющий полную актуальную (!) информацию по API Битрикс",
		'CHANGELOG'		=> 'Просто обернул скрипт Дениса Шаромова в гем. :)',
		'REQUIREMENTS'	=> '',
	);

	public function needAdminPage(){
		return true;
	}
	public function showAdminPage(){
		require_once( dirname(__FILE__).'/options/adminOptionPage.php' );
	}
}
?>