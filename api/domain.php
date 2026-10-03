<?php
declare(strict_types=1);

function demand(bool $ok, string $message): void {
    if (!$ok) throw new InvalidArgumentException($message);
}
function valid_text(mixed $value, int $max = 500): bool {
    if(!is_string($value))return false;
    $length=preg_match_all('/./us',$value);
    return $length!==false && $length<=$max;
}
function valid_id(mixed $value): bool {
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $value) === 1;
}
function valid_time(mixed $value): bool {
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?(Z|[+-]\d{2}:\d{2})$/D', $value) === 1 && strtotime($value) !== false;
}
function integer_amount(mixed $value, bool $negative = true): bool {
    return is_int($value) && abs($value) <= 100000000000000 && ($negative || $value >= 0);
}
function decimal_number(mixed $value): bool {
    return (is_int($value) || is_float($value)) && is_finite((float)$value) && $value > 0 && $value <= 100000000000000;
}
function index_rows(array $rows, string $name): array {
    $map = [];
    foreach ($rows as $row) {
        demand(is_array($row) && valid_id($row['id'] ?? null), "ID $name tidak valid.");
        demand(!isset($map[$row['id']]), "ID ganda di $name.");
        $map[$row['id']] = $row;
    }
    return $map;
}
function validate_state(array $s): array {
    $currencies = ['IDR'=>0,'USD'=>2,'EUR'=>2,'SGD'=>2,'JPY'=>0,'MYR'=>2,'AUD'=>2,'GBP'=>2];
    $tables = ['users','workspaces','workspace_members','accounts','institutions','transactions','transaction_entries','categories','instruments','investment_transactions','market_prices','exchange_rates','currencies'];
    demand(($s['version'] ?? null) === 2, 'Format database harus versi 2.');
    foreach ($tables as $table) demand(isset($s[$table]) && is_array($s[$table]) && array_is_list($s[$table]) && count($s[$table]) <= 10000, "Tabel $table tidak valid / terlalu besar.");
    demand(count($s['users']) === 1 && count($s['workspaces']) === 1 && count($s['workspace_members']) === 1, 'Hanya satu pemilik dan workspace didukung.');
    $uid = $s['users'][0]['id'] ?? null; $wid = $s['workspaces'][0]['id'] ?? null;
    demand(valid_id($uid) && valid_id($wid), 'Identitas workspace tidak valid.');
    demand(valid_text($s['users'][0]['name'] ?? null,80) && valid_text($s['workspaces'][0]['name'] ?? null,80) && ($s['workspaces'][0]['base_currency'] ?? '')==='IDR' && ($s['users'][0]['timezone'] ?? '')==='Asia/Jakarta' && ($s['users'][0]['default_currency'] ?? '')==='IDR', 'Profil workspace tidak valid.');
    demand(($s['workspace_members'][0]['user_id'] ?? '')===$uid && ($s['workspace_members'][0]['workspace_id'] ?? '')===$wid && ($s['workspace_members'][0]['role'] ?? '')==='owner', 'Keanggotaan tidak valid.');
    $accounts=index_rows($s['accounts'],'accounts'); $institutions=index_rows($s['institutions'],'institutions');
    $transactions=index_rows($s['transactions'],'transactions'); $categories=index_rows($s['categories'],'categories');
    $instruments=index_rows($s['instruments'],'instruments'); index_rows($s['transaction_entries'],'entries'); index_rows($s['investment_transactions'],'trades');
    foreach($institutions as $i) demand(valid_text($i['name']??null,80) && trim($i['name'])!=='' && in_array($i['type']??'', ['bank','broker','exchange','ewallet','other'],true), 'Institusi tidak valid.');
    foreach($accounts as $a) {
        demand(($a['workspace_id']??null)===$wid && valid_text($a['name']??null,80) && trim($a['name'])!=='' && valid_text($a['note']??null) && isset($currencies[$a['currency']??'']) && in_array($a['account_type']??'', ['cash','bank','ewallet','investment','credit_card','loan','other'],true) && in_array($a['status']??'', ['active','archived','closed'],true), 'Akun tidak valid.');
        demand(array_key_exists('institution_id',$a) && ($a['institution_id']===null || isset($institutions[$a['institution_id']])), 'Institusi akun tidak ditemukan.');
    }
    foreach($categories as $c) demand(($c['workspace_id']??null)===$wid && valid_text($c['name']??null,80) && trim($c['name'])!=='' && in_array($c['type']??'', ['income','expense'],true), 'Kategori tidak valid.');
    foreach($instruments as $i) demand(($i['instrument_type']??null)==='gold' && ($i['currency']??null)==='IDR' && valid_text($i['name']??null,80) && trim($i['name'])!=='' && valid_text($i['note']??null) && decimal_number($i['purity']??null) && $i['purity']<=100, 'Instrumen emas tidak valid.');
    foreach($transactions as $t) {
        demand(($t['workspace_id']??null)===$wid && ($t['created_by']??null)===$uid && valid_time($t['transaction_date']??null) && valid_text($t['description']??null,200) && in_array($t['transaction_type']??'', ['income','expense','transfer','investment','adjustment'],true), 'Transaksi tidak valid.');
        demand(array_key_exists('category_id',$t) && ($t['category_id']===null || isset($categories[$t['category_id']]) && $categories[$t['category_id']]['type']===$t['transaction_type']), 'Kategori transaksi tidak cocok.');
    }
    $entries=[]; $sums=[]; $balances=array_fill_keys(array_keys($accounts),0);
    foreach($s['transaction_entries'] as $e) {
        demand(isset($transactions[$e['transaction_id']??'']) && isset($currencies[$e['currency']??'']) && integer_amount($e['amount_minor']??null), 'Entri ledger tidak valid.');
        demand(array_key_exists('account_id',$e), 'Akun ledger tidak valid.');
        if($e['account_id']!==null) {
            demand(isset($accounts[$e['account_id']]) && $accounts[$e['account_id']]['currency']===$e['currency'], 'Akun / mata uang ledger tidak cocok.');
            $balances[$e['account_id']]+=$e['amount_minor'];
            demand(abs($balances[$e['account_id']])<=9007199254740991, 'Saldo terlalu besar.');
        } else demand(in_array($e['offset_type']??'', ['equity','income','expense','fx','investment'],true), 'Penyeimbang tidak valid.');
        $entries[$e['transaction_id']][]=$e; $key=$e['transaction_id'].'/'.$e['currency']; $sums[$key]=($sums[$key]??0)+$e['amount_minor'];
    }
    foreach($sums as $sum) demand($sum===0, 'Ledger tidak seimbang per mata uang.');
    foreach($transactions as $tid=>$t) {
        $rows=$entries[$tid]??[]; demand(count($rows)>=2,'Ledger transaksi belum lengkap.');
        $real=array_values(array_filter($rows,fn($e)=>$e['account_id']!==null));
        if(in_array($t['transaction_type'],['income','expense','adjustment'],true)) {
            $offset=array_values(array_filter($rows,fn($e)=>$e['account_id']===null)); $kind=$t['transaction_type']==='adjustment'?'equity':$t['transaction_type'];
            demand(count($rows)===2 && count($real)===1 && count($offset)===1 && $offset[0]['offset_type']===$kind && $real[0]['amount_minor']!==0, 'Entri tidak sesuai jenis transaksi.');
            if($kind==='income')demand($real[0]['amount_minor']>0,'Pemasukan harus positif.');
            if($kind==='expense')demand($real[0]['amount_minor']<0,'Pengeluaran harus negatif.');
        }
        if($t['transaction_type']==='transfer') {
            demand(count($real)===2 && $real[0]['account_id']!==$real[1]['account_id'] && $real[0]['amount_minor']*$real[1]['amount_minor']<0, 'Transfer tidak valid.');
            demand(count($rows)===($real[0]['currency']===$real[1]['currency']?2:4), 'Penyeimbang transfer tidak cocok.');
            foreach($rows as $e) if($e['account_id']===null)demand($e['offset_type']==='fx','Penyeimbang kurs tidak cocok.');
        }
    }
    $tradeCounts=[]; $trades=$s['investment_transactions']; $order=array_flip(array_keys($transactions));
    foreach($trades as $t) {
        demand(($t['workspace_id']??null)===$wid && isset($accounts[$t['account_id']??'']) && $accounts[$t['account_id']]['account_type']==='investment' && isset($instruments[$t['instrument_id']??'']) && isset($transactions[$t['transaction_id']??'']), 'Referensi investasi tidak valid.');
        $tx=$transactions[$t['transaction_id']];
        demand($tx['transaction_type']==='investment' && ($t['transaction_date']??null)===$tx['transaction_date'] && ($t['currency']??null)==='IDR' && in_array($t['type']??'', ['opening','buy','sell'],true) && integer_amount($t['quantity_micrograms']??null,false) && $t['quantity_micrograms']>0 && array_key_exists('total_minor',$t) && ($t['total_minor']===null || integer_amount($t['total_minor'],false)) && integer_amount($t['fee_minor']??null,false) && integer_amount($t['tax_minor']??null,false), 'Detail investasi tidak valid.');
        $tradeCounts[$tx['id']]=($tradeCounts[$tx['id']]??0)+1;
        $real=array_values(array_filter($entries[$tx['id']],fn($e)=>$e['account_id']!==null));
        if($t['type']==='opening') demand(count($real)===0 && $t['fee_minor']===0 && $t['tax_minor']===0, 'Kepemilikan awal tidak boleh mengubah kas.');
        else {
            demand(integer_amount($t['total_minor'],false) && $t['total_minor']>0 && isset($accounts[$t['cash_account_id']??'']) && $accounts[$t['cash_account_id']]['currency']==='IDR', 'Pembayaran emas harus memakai akun IDR.');
            $net=$t['type']==='buy'?-($t['total_minor']+$t['fee_minor']+$t['tax_minor']):$t['total_minor']-$t['fee_minor']-$t['tax_minor'];
            demand($t['type']!=='sell' || $net>=0,'Biaya penjualan melebihi nilai bruto.');
            demand(count($real)===1 && count($entries[$tx['id']])===2 && $real[0]['account_id']===$t['cash_account_id'] && $real[0]['amount_minor']===$net,'Entri kas tidak sesuai investasi.');
        }
    }
    foreach($transactions as $t)if($t['transaction_type']==='investment')demand(($tradeCounts[$t['id']]??0)===1,'Detail investasi harus tepat satu per transaksi.');
    usort($trades,fn($a,$b)=>(new DateTimeImmutable($a['transaction_date']))<=>(new DateTimeImmutable($b['transaction_date'])) ?: $order[$a['transaction_id']]<=>$order[$b['transaction_id']]);
    $positions=[];
    foreach($trades as $t) {
        $key=$t['account_id'].'/'.$t['instrument_id'];
        $p=$positions[$key]??['id'=>$key,'workspace_id'=>$wid,'account_id'=>$t['account_id'],'instrument_id'=>$t['instrument_id'],'quantity_micrograms'=>0,'cost_basis_minor'=>0,'realized_pnl_minor'=>0,'average_cost'=>null];
        if($t['type']==='opening'||$t['type']==='buy') {
            $p['quantity_micrograms']+=$t['quantity_micrograms'];
            $p['cost_basis_minor']=$p['cost_basis_minor']===null||$t['total_minor']===null?null:$p['cost_basis_minor']+$t['total_minor']+$t['fee_minor']+$t['tax_minor'];
        } else {
            demand($t['quantity_micrograms']<=$p['quantity_micrograms'], 'Penjualan melebihi kepemilikan pada tanggal transaksi.');
            $removed=$p['cost_basis_minor']===null?null:($t['quantity_micrograms']===$p['quantity_micrograms']?$p['cost_basis_minor']:(int)floor($p['cost_basis_minor']*$t['quantity_micrograms']/$p['quantity_micrograms']+0.5));
            $p['realized_pnl_minor']=$removed===null||$p['realized_pnl_minor']===null?null:$p['realized_pnl_minor']+$t['total_minor']-$t['fee_minor']-$t['tax_minor']-$removed;
            $p['quantity_micrograms']-=$t['quantity_micrograms'];
            $p['cost_basis_minor']=$p['quantity_micrograms']===0?0:($removed===null?null:$p['cost_basis_minor']-$removed);
        }
        foreach(['quantity_micrograms','cost_basis_minor','realized_pnl_minor'] as $field)demand($p[$field]===null || is_int($p[$field]) && abs($p[$field])<=9007199254740991,'Nilai posisi terlalu besar.');
        $p['average_cost']=$p['cost_basis_minor']===null||$p['quantity_micrograms']===0?null:$p['cost_basis_minor']/($p['quantity_micrograms']/1000000);
        $p['updated_at']=$t['transaction_date'];$positions[$key]=$p;
    }
    foreach($accounts as $a)if($a['status']!=='active') {
        demand($balances[$a['id']]===0,'Akun nonaktif memiliki saldo.');
        foreach($positions as $p)if($p['account_id']===$a['id'])demand($p['quantity_micrograms']===0,'Akun nonaktif memiliki emas.');
    }
    $priceMap=[];
    foreach($s['market_prices'] as $p) {
        demand(isset($instruments[$p['instrument_id']??'']) && decimal_number($p['price']??null) && ($p['currency']??null)==='IDR' && valid_time($p['timestamp']??null) && in_array($p['source']??'', ['spot','manual'],true), 'Harga instrumen tidak valid.');
        $priceMap[$p['instrument_id'].'/'.$p['timestamp'].'/'.$p['source']]=$p;
    }
    foreach($s['exchange_rates'] as $r)demand(($r['base_currency']??null)==='USD' && isset($currencies[$r['quote_currency']??'']) && decimal_number($r['rate']??null) && valid_time($r['timestamp']??null) && valid_text($r['source']??null,80),'Kurs tidak valid.');
    demand(array_key_exists('market',$s),'Cache pasar tidak tersedia.');
    if($s['market']!==null) {
        $m=$s['market']; demand(is_array($m) && in_array($m['mode']??'', ['spot','manual'],true) && valid_time($m['fetchedAt']??null) && array_key_exists('goldIDR',$m) && ($m['goldIDR']===null||decimal_number($m['goldIDR'])) && array_key_exists('goldAt',$m) && ($m['goldAt']===null||valid_time($m['goldAt'])) && array_key_exists('fxAt',$m) && ($m['fxAt']===null||valid_time($m['fxAt'])) && isset($m['rates']) && (is_array($m['rates'])||is_object($m['rates'])), 'Cache pasar tidak valid.');
        $rates=(array)$m['rates'];foreach($rates as $r)demand(decimal_number($r),'Nilai kurs tidak valid.');
        demand(!$rates || ($rates['USD']??null)==1 && decimal_number($rates['IDR']??null),'Kurs dasar tidak valid.');
        $s['market']['rates']=(object)$rates;
    }
    $s['positions']=array_values($positions);$s['market_prices']=array_values($priceMap);
    $s['currencies']=array_map(fn($code)=>['code'=>$code,'decimal_places'=>$currencies[$code]],array_keys($currencies));
    return array_intersect_key($s,array_flip(array_merge(['version','positions','market'],$tables)));
}
function canonical_state(array $s, array $user, string $wid): array {
    $s=validate_state($s);
    $s['users']=[['id'=>$user['id'],'name'=>$user['name'],'default_currency'=>'IDR','timezone'=>'Asia/Jakarta']];
    $s['workspaces'][0]['id']=$wid;
    $s['workspace_members']=[['workspace_id'=>$wid,'user_id'=>$user['id'],'role'=>'owner']];
    foreach(['accounts','categories','transactions','investment_transactions','positions'] as $table)foreach($s[$table] as &$row)$row['workspace_id']=$wid;
    unset($row);
    foreach($s['transactions'] as &$row)$row['created_by']=$user['id'];unset($row);
    return $s;
}
