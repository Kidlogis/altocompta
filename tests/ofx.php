<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\OfxParser;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

// OFX 1.x : SGML, balises non fermees, en-tete a deux points
$ofx1 = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\nCHARSET:1252\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>\n<CURDEF>EUR\n<BANKACCTFROM><ACCTID>FR7630004000031234567890143<ACCTTYPE>CHECKING</BANKACCTFROM>\n<BANKTRANLIST>\n<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260812120000[+1:CET]<TRNAMT>1440.00<FITID>TX001<NAME>VIR SEPA OF VENDEE<MEMO>VIR SEPA OF VENDEE FORMATION REF FA-2026-0341</STMTTRN>\n<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260810<TRNAMT>-780.00<FITID>TX002<NAME>PRLV URSSAF</STMTTRN>\n<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260809<TRNAMT>-74.30<FITID>TX003<NAME>CB TOTAL<MEMO>CB TOTAL ENERGIES</STMTTRN>\n</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";

ok(OfxParser::estOfx($ofx1), 'OFX 1.x reconnu');
$a = OfxParser::analyser($ofx1);
ok(count($a['lignes'])===3, 'trois transactions lues');
ok($a['compte']==='FR7630004000031234567890143', 'ACCTID capture');
ok($a['lignes'][0]['montant']===1440.0, 'credit +1440');
ok($a['lignes'][1]['montant']===-780.0, 'debit -780');
ok($a['lignes'][0]['date_operation']==='2026-08-12', 'date avec heure et fuseau');
ok($a['lignes'][1]['date_operation']==='2026-08-10', 'date simple AAAAMMJJ');
ok(str_contains($a['lignes'][0]['libelle'],'FA-2026-0341'), 'MEMO prefere a NAME (plus complet)');
ok($a['lignes'][1]['libelle']==='PRLV URSSAF', 'NAME utilise quand MEMO absent');
ok($a['lignes'][0]['external_id']==='TX001', 'FITID capture');

echo "\nOFX 2.x (XML)\n";
$ofx2 = '<?xml version="1.0" encoding="UTF-8"?><OFX><BANKTRANLIST>'
  . '<STMTTRN><DTPOSTED>20260807</DTPOSTED><TRNAMT>-18.90</TRNAMT><FITID>N1</FITID>'
  . '<NAME>NETCUP GMBH</NAME></STMTTRN></BANKTRANLIST></OFX>';
ok(OfxParser::estOfx($ofx2), 'OFX 2.x reconnu');
$b = OfxParser::analyser($ofx2);
ok(count($b['lignes'])===1 && $b['lignes'][0]['montant']===-18.9, 'balises XML fermees lues');

echo "\nRobustesse\n";
$c = OfxParser::analyser("<OFX><STMTTRN><DTPOSTED>PASUNEDATE<TRNAMT>10.00</STMTTRN><STMTTRN><DTPOSTED>20260801<TRNAMT>abc</STMTTRN><STMTTRN><DTPOSTED>20260801<TRNAMT>5,50<NAME>Bonne</STMTTRN></OFX>");
ok(count($c['lignes'])===1 && $c['ignorees']===2, 'transactions invalides ignorees, valide gardee');
$d = OfxParser::analyser("<OFX><STMTTRN><DTPOSTED>20260230<TRNAMT>10.00<NAME>X</STMTTRN></OFX>");
ok(count($d['lignes'])===0, '30 fevrier rejete');
$acc = OfxParser::analyser(mb_convert_encoding("OFXHEADER:100\nCHARSET:1252\n<OFX><STMTTRN><DTPOSTED>20260801<TRNAMT>-10.00<NAME>CAFE REUNION ELECTRICITE</STMTTRN></OFX>",'Windows-1252','UTF-8'));
ok(count($acc['lignes'])===1, 'Windows-1252 gere');
ok(!OfxParser::estOfx("Date;Libelle;Montant\n01/01/2026;X;10"), 'un CSV n est pas pris pour de l OFX');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
