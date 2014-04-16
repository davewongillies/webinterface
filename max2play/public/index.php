<?php
/**
	 Index Page
	
	 @Copyright 2014 Stefan Rick
	 @author Stefan Rick
	 Mail: stefan@rick-software.de
 	 Web: http://www.netzberater.de
	
	 This program is free software; you can redistribute it and/or modify
	 it under the terms of the GNU General Public License as published by
	 the Free Software Foundation; either version 2 of the License, or
	 (at your option) any later version.
	
	 This program is distributed in the hope that it will be useful,
	 but WITHOUT ANY WARRANTY; without even the implied warranty of
	 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	 GNU General Public License for more details.
	
	 You should have received a copy of the GNU General Public License along
	 with this program; if not, write to the Free Software Foundation, Inc.,
	 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */ 

?>

<?php 

include_once('../application/bootstrap.php');

include_once('header.php');

//search for Controller and View by Name in URL
$pagename = preg_replace('=[^0-9a-zA-Z\.]=','',$_GET['page']);
if($pagename == '')
	$pagename = 'Index.php';
include_once('../application/controller/'.$pagename);
include_once('../application/view/'.strtolower($pagename));

include_once('footer.php');	
			