<?php
declare(strict_types=1);
require __DIR__.'/../api/domain.php';
require __DIR__.'/../api/storage.php';
if($argc!==2)throw new RuntimeException('Provide the generated v2 fixture JSON path.');
$fixture=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);
function check(bool $ok,string $name): void {if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function rejects(callable $fn,string $name): void {try{$fn();}catch(InvalidArgumentException){check(true,$name);return;}throw new RuntimeException('Expected rejection: '.$name);}
$s=validate_state($fixture);
check($s['positions'][0]['quantity_micrograms']===6000000,'server recomputes remaining grams');
check($s['positions'][0]['cost_basis_minor']===6060000,'server weighted cost matches JS');
check($s['positions'][0]['realized_pnl_minor']===900000,'server realized profit matches JS');
$bad=$fixture;$bad['transaction_entries'][0]['amount_minor']++;rejects(fn()=>validate_state($bad),'unbalanced ledger rejected');
$bad=$fixture;$bad['investment_transactions'][0]['instrument_id']='missing';rejects(fn()=>validate_state($bad),'broken investment FK rejected');
$bad=$fixture;$bad['investment_transactions'][1]['quantity_micrograms']=11000000;rejects(fn()=>validate_state($bad),'overselling rejected server side');
$bad=$fixture;$bad['investment_transactions'][1]['transaction_date']='2026-10-02T00:00:00Z';$bad['transactions'][2]['transaction_date']='2026-10-02T00:00:00Z';rejects(fn()=>validate_state($bad),'backdated overselling rejected');
$bad=$fixture;$bad['accounts'][0]['status']='archived';rejects(fn()=>validate_state($bad),'archived nonzero wallet rejected');
$bad=$fixture;$bad['transaction_entries'][]=$bad['transaction_entries'][0];rejects(fn()=>validate_state($bad),'duplicate ledger ID rejected');
$bad=$fixture;$bad['market']=['mode'=>'manual','goldIDR'=>2000000,'goldAt'=>'2026-10-03T00:00:00Z','fxAt'=>null,'fetchedAt'=>'2026-10-03T00:00:00Z','rates'=>[]];$manual=validate_state($bad);check(str_contains(json_data($manual),'"rates":{}'),'empty rates serialize as an object for JS compatibility');
$canonical=canonical_state($fixture,['id'=>'server-user','name'=>'Owner'],'server-workspace');
check($canonical['transactions'][0]['created_by']==='server-user'&&$canonical['accounts'][0]['workspace_id']==='server-workspace','import ownership canonicalized to authenticated owner');
check(validate_state($canonical)['positions'][0]['workspace_id']==='server-workspace','canonical result passes independent validation');
$bad=$fixture;$bad['positions'][0]['quantity_micrograms']=999;check(validate_state($bad)['positions'][0]['quantity_micrograms']===6000000,'submitted cache ignored');
check(sql_time('2026-10-03T07:00:00+07:00')==='2026-10-03 00:00:00.000','SQL dates stored in UTC');
