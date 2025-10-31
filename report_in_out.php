<?php
/* Copyright (C) 2005       Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2013-2023  Charlene BENKE          <charlene@patas-monkey.com>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024		Frédéric France			<frederic.france@free.fr>
 * Copyright (C) 2025		Florian HENRY			<florian.henry@scopen.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *		\file	     ezcompta/report_in_out.php
 *		\ingroup     ezcompta
 *		\brief       Page to report input-output of a bank account
 */


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
dol_include_once('/ezcompta/class/html.formezcompta.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

//inspired from htdocs/compta/bank/annuel.php

// Load translation files required by the page
$langs->loadLangs(array('banks', 'categories'));

$WIDTH = DolGraph::getDefaultGraphSizeForStats('width', '380'); // Large for one graph in a smarpthone.
$HEIGHT = DolGraph::getDefaultGraphSizeForStats('height', '160');

$bankaccounts = GETPOST('bankaccounts','array') ? GETPOST('bankaccounts','array') : [];
$optioncss = GETPOST('optioncss', 'alpha');
// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
$hookmanager->initHooks(array('ezcomptakannualreport', 'globalcard'));

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
	$bankaccounts = [];
}


// Security check
$fieldvalue = (!empty($id) ? $id : (!empty($ref) ? $ref : ''));
$fieldtype = (!empty($ref) ? 'ref' : 'rowid');
if ($user->socid) {
	$socid = $user->socid;
}
if (!$user->hasRight('banque','lire')) {
	accessforbidden();
}

$year_start = GETPOST('year_start');
//$year_current = strftime("%Y", time());
$year_current = (int) dol_print_date(time(), "%Y");
if (!$year_start) {
	$year_start = $year_current - 2;
	$year_end = $year_current;
} else {
	$year_end = $year_start + 2;
}



/*
 * View
 */
$error = 0;

$form = new Form($db);
$fromezcompta = new FormEzCompta($db);

if (empty($bankaccounts)) {
	$bankaccounts = array_keys($fromezcompta->getBankAccounts());
}

$annee = '';
$totentrees = array();
$totsorties = array();
$year_end_for_table = ($year_end - (getDolGlobalInt('SOCIETE_FISCAL_MONTH_START') > 1 ? 1 : 0));

$title = $langs->trans("IOMonthlyReporting");
$helpurl = "";
llxHeader('', $title, $helpurl);

// Ce rapport de tresorerie est base sur llx_bank (car doit inclure les transactions sans facture)
// plutot que sur llx_paiement + llx_paiementfourn
$encaiss = array();
$decaiss = array();
if (!empty($bankaccounts)) {
	$sql = "SELECT SUM(b.amount_credit)";
	$sql .= ", date_format(b.bdate,'%Y-%m') as dm";
	$sql .= " FROM " . MAIN_DB_PREFIX . "bank_import as b";
	$sql .= ", " . MAIN_DB_PREFIX . "bank_account as ba";
	$sql .= " WHERE b.id_account = ba.rowid";
	$sql .= " AND ba.entity IN (" . getEntity('bank_account') . ")";
	$sql .= " AND b.amount_credit >= 0";

	$sql .= " AND b.id_account IN (" . $db->sanitize($db->escape(implode(',', $bankaccounts))) . ")";

	$sql .= " GROUP BY dm";

	$resql = $db->query($sql);

	if ($resql && !empty($bankaccounts)) {
		$num = $db->num_rows($resql);
		$i = 0;
		while ($i < $num) {
			$row = $db->fetch_row($resql);
			$encaiss[$row[1]] = (float)$row[0];
			$i++;
		}
	} else {
		dol_print_error($db);
	}

	$sql = "SELECT SUM(b.amount_debit)";
	$sql .= ", date_format(b.bdate,'%Y-%m') as dm";
	$sql .= " FROM " . MAIN_DB_PREFIX . "bank_import as b";
	$sql .= ", " . MAIN_DB_PREFIX . "bank_account as ba";
	$sql .= " WHERE b.id_account = ba.rowid";
	$sql .= " AND ba.entity IN (" . getEntity('bank_account') . ")";
	$sql .= " AND b.amount_debit <= 0";
	$sql .= " AND b.id_account IN (" . $db->sanitize($db->escape(implode(',', $bankaccounts))) . ")";
	$sql .= " GROUP BY dm";

	$resql = $db->query($sql);
	if ($resql && !empty($bankaccounts)) {
		$num = $db->num_rows($resql);
		$i = 0;
		while ($i < $num) {
			$row = $db->fetch_row($resql);
			$decaiss[$row[1]] = $row[0]*-1;
			$i++;
		}
	} else {
		dol_print_error($db);
	}
}
// Tabs tab / graph
print dol_get_fiche_head([], 'annual', $langs->trans("FinancialAccount"), 0, 'account');

$title = $langs->trans("FinancialAccount");
$parambk='';

if (!empty($bankaccounts)) {
	$parambk .= implode('&bankaccounts[]=', $bankaccounts);
	$parambk = '&bankaccounts[]='.$parambk;
}
$link = ($year_start ? '<a href="'.$_SERVER["PHP_SELF"].'?'.$parambk.'&year_start='.($year_start - 1).'">'.img_previous('', 'class="valignbottom"')."</a> ".$langs->trans("Year").' <a href="'.$_SERVER["PHP_SELF"].'?'.$parambk.'&year_start='.($year_start + 1).'">'.img_next('', 'class="valignbottom"').'</a>' : '');

$linkback='';
$morehtmlref = '';

print $langs->trans("FinancialAccount");
print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="year" value="'.$year_start.'">';

$fromezcompta->selectBankAccounts($bankaccounts);

print $form->showFilterButtons();

print '</form>';

print dol_get_fiche_end();


// Affiche tableau
print load_fiche_titre('', $link, '');

print '<div class="div-table-responsive">'; // You can use div-table-responsive-no-min if you don't need reserved height for your table
print '<table class="noborder centpercent">';

print '<tr class="liste_titre"><td class="liste_titre">'.$langs->trans("Month").'</td>';
for ($annee = $year_start; $annee <= $year_end_for_table; $annee++) {
	print '<td width="20%" colspan="2" class="liste_titre borderrightlight center">';
	print $annee;
	if (getDolGlobalInt('SOCIETE_FISCAL_MONTH_START') > 1) {
		print '-'.($annee + 1);
	}
	print '</td>';
}
print '</tr>';

print '<tr class="liste_titre">';
print '<td class="liste_titre">&nbsp;</td>';
for ($annee = $year_start; $annee <= $year_end_for_table; $annee++) {
	print '<td class="liste_titre" align="center">'.$langs->trans("Debit").'</td><td class="liste_titre" align="center">'.$langs->trans("Credit").'</td>';
}
print '</tr>';

for ($annee = $year_start; $annee <= $year_end_for_table; $annee++) {
	$totsorties[$annee] = 0;
	$totentrees[$annee] = 0;
}
$nb_mois_decalage = getDolGlobalInt('SOCIETE_FISCAL_MONTH_START') ? (getDolGlobalInt('SOCIETE_FISCAL_MONTH_START')	 - 1) : 0;
for ($mois = 1 + $nb_mois_decalage; $mois <= 12 + $nb_mois_decalage; $mois++) {
	$mois_modulo = $mois;
	if ($mois > 12) {
		$mois_modulo = $mois - 12;
	}

	print '<tr class="oddeven">';
	print "<td>".dol_print_date(dol_mktime(1, 1, 1, $mois_modulo, 1, 2000), "%B")."</td>";

	for ($annee = $year_start; $annee <= $year_end_for_table; $annee++) {
		$annee_decalage = $annee;
		if ($mois > 12) {
			$annee_decalage = $annee + 1;
		}
		$case = dol_print_date(dol_mktime(12, 0, 0, $mois_modulo, 1, $annee_decalage), "%Y-%m");

		print '<td class="right" width="10%">&nbsp;';
		if (isset($decaiss[$case]) && $decaiss[$case] > 0) {
			print price($decaiss[$case]);
			$totsorties[$annee] += $decaiss[$case];
		}
		print "</td>";

		print '<td class="right borderrightlight" width="10%">&nbsp;';
		if (isset($encaiss[$case]) && $encaiss[$case] > 0) {
			print price($encaiss[$case]);
			$totentrees[$annee] += $encaiss[$case];
		}
		print "</td>";
	}
	print '</tr>';
}

// Total debit-credit
print '<tr class="liste_total"><td><b>'.$langs->trans("Total")."</b></td>";
for ($annee = $year_start; $annee <= $year_end_for_table; $annee++) {
	print '<td class="right nowraponall"><b>'. (isset($totsorties[$annee]) ? price($totsorties[$annee]) : '') .'</b></td>';
	print '<td class="right nowraponall"><b>'. (isset($totentrees[$annee]) ? price($totentrees[$annee]) : '') .'</b></td>';
}
print "</tr>\n";

print "</table>";

print "</div>";


// End of page
llxFooter();
$db->close();
