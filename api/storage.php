<?php
declare(strict_types=1);

function json_data(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
function sql_time(string $value): string {
    return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
}
function insert_row(PDO $db, string $table, array $values): void {
    // Table and columns originate exclusively from the fixed mappings below.
    $columns=array_keys($values);
    $sql='INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
    $db->prepare($sql)->execute(array_values($values));
}
function sync_tables(PDO $db, string $wid, array $s): void {
    foreach(['market_prices','exchange_rates','positions','investment_transactions','transaction_entries','transactions','accounts','instruments','categories','institutions','workspace_members'] as $table) {
        $db->prepare('DELETE FROM '.$table.' WHERE workspace_id=?')->execute([$wid]);
    }
    insert_row($db,'workspace_members',['workspace_id'=>$wid,'user_id'=>$s['users'][0]['id'],'role'=>'owner']);
    $maps=[
        'institutions'=>['id','name','type'],
        'accounts'=>['id','institution_id','name','account_type','currency','status'],
        'categories'=>['id','name','type'],
        'instruments'=>['id','name','instrument_type','purity','currency'],
        'transactions'=>['id','category_id','transaction_type','transaction_date','description'],
        'transaction_entries'=>['id','transaction_id','account_id','amount_minor','currency','offset_type'],
        'investment_transactions'=>['id','transaction_id','account_id','instrument_id','cash_account_id','type','quantity_micrograms','total_minor','fee_minor','tax_minor','transaction_date'],
        'positions'=>['account_id','instrument_id','quantity_micrograms','cost_basis_minor','realized_pnl_minor','average_cost','updated_at'],
        'market_prices'=>['instrument_id','timestamp','source','price','currency'],
        'exchange_rates'=>['base_currency','quote_currency','rate','timestamp','source'],
    ];
    foreach($maps as $table=>$columns)foreach($s[$table] as $row) {
        $values=['workspace_id'=>$wid];foreach($columns as $column){$value=$row[$column]??null;if(in_array($column,['transaction_date','updated_at','timestamp'],true))$value=sql_time($value);$values[$column]=$value;}
        if(in_array($table,['institutions','accounts','categories','instruments','transactions','investment_transactions'],true))$values['payload_json']=json_data($row);
        insert_row($db,$table,$values);
    }
}
