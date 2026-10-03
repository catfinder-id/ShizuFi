'use strict';
// Domain model is independent of browser UI. Monetary entries use integer minor units.
(function(root) {
  const currencies = {IDR:0,USD:2,EUR:2,SGD:2,JPY:0,MYR:2,AUD:2,GBP:2};
  const types = ['cash','bank','ewallet','investment','credit_card','loan','other'];
  const fail = message => { throw Error(message); };
  const safe = n => Number.isSafeInteger(n);
  const text = (s, max=500) => typeof s==='string' && s.length<=max;
  const timestamp = s => typeof s==='string' && Number.isFinite(Date.parse(s));
  const clone = data => JSON.parse(JSON.stringify(data));
  const id = () => root.crypto.randomUUID();
  const now = () => new Date().toISOString();
  const minor = (n,currency) => {
    if (!Object.hasOwn(currencies,currency) || !Number.isFinite(Number(n))) fail('Jumlah / mata uang tidak valid.');
    const v = Math.round(Number(n)*10**currencies[currency]);
    if(!safe(v) || Math.abs(v)>1e14) fail('Jumlah melebihi batas pencatatan.');
    return v;
  };
  const grams = n => {const v=Math.round(Number(n)*1e6);if(!safe(v)||v<=0||v>1e14)fail('Berat emas tidak valid.');return v;};
  function empty() {
    const at=now(),user=id(),workspace=id();
    return {version:2,users:[{id:user,name:'Pemilik',default_currency:'IDR',timezone:'Asia/Jakarta'}],workspaces:[{id:workspace,name:'Keuangan pribadi',type:'personal',base_currency:'IDR',created_at:at}],workspace_members:[{workspace_id:workspace,user_id:user,role:'owner'}],accounts:[],institutions:[],transactions:[],transaction_entries:[],categories:[{id:id(),workspace_id:workspace,name:'Pendapatan',type:'income'},{id:id(),workspace_id:workspace,name:'Kebutuhan sehari-hari',type:'expense'},{id:id(),workspace_id:workspace,name:'Lainnya',type:'expense'}],instruments:[],investment_transactions:[],positions:[],market_prices:[],exchange_rates:[],currencies:Object.entries(currencies).map(([code,decimal_places])=>({code,decimal_places})),market:null};
  }
  function account(s,key){return s.accounts.find(a=>a.id===key)||fail('Akun tidak ditemukan.');}
  function instrument(s,key){return s.instruments.find(a=>a.id===key)||fail('Instrumen tidak ditemukan.');}
  function balance(s,key){return s.transaction_entries.filter(e=>e.account_id===key).reduce((n,e)=>n+e.amount_minor,0);}
  function balances(s){const result=Object.fromEntries(s.accounts.map(a=>[a.id,0]));for(const e of s.transaction_entries)if(e.account_id!==null)result[e.account_id]+=e.amount_minor;return result;}
  function position(s,aid,iid){return s.positions.find(p=>p.account_id===aid&&p.instrument_id===iid);}
  function rebuild(s) {
    const map=new Map(),order=new Map(s.transactions.map((t,i)=>[t.id,i]));
    const trades=[...s.investment_transactions].sort((a,b)=>Date.parse(a.transaction_date)-Date.parse(b.transaction_date)||order.get(a.transaction_id)-order.get(b.transaction_id));
    for(const t of trades){
      const key=`${t.account_id}/${t.instrument_id}`;
      let p=map.get(key);
      if(!p){p={id:key,workspace_id:t.workspace_id,account_id:t.account_id,instrument_id:t.instrument_id,quantity_micrograms:0,cost_basis_minor:0,average_cost:null,realized_pnl_minor:0,updated_at:t.transaction_date};map.set(key,p);}
      if(t.type==='opening'||t.type==='buy') {
        p.quantity_micrograms+=t.quantity_micrograms;
        p.cost_basis_minor=p.cost_basis_minor===null||t.total_minor===null?null:p.cost_basis_minor+t.total_minor+t.fee_minor+t.tax_minor;
      } else {
        if(t.quantity_micrograms>p.quantity_micrograms)fail('Emas yang dijual melebihi kepemilikan pada tanggal transaksi.');
        const removed=p.cost_basis_minor===null?null:t.quantity_micrograms===p.quantity_micrograms?p.cost_basis_minor:Math.round(p.cost_basis_minor*t.quantity_micrograms/p.quantity_micrograms);
        const net=t.total_minor-t.fee_minor-t.tax_minor;
        p.realized_pnl_minor=removed===null||p.realized_pnl_minor===null?null:p.realized_pnl_minor+net-removed;
        p.quantity_micrograms-=t.quantity_micrograms;
        p.cost_basis_minor=p.quantity_micrograms===0?0:removed===null?null:p.cost_basis_minor-removed;
      }
      p.average_cost=p.cost_basis_minor===null||!p.quantity_micrograms?null:p.cost_basis_minor/(p.quantity_micrograms/1e6);
      p.updated_at=t.transaction_date;
      if(!safe(p.quantity_micrograms)||p.cost_basis_minor!==null&&!safe(p.cost_basis_minor)||p.realized_pnl_minor!==null&&!safe(p.realized_pnl_minor))fail('Nilai posisi melebihi batas pencatatan.');
    }
    s.positions=[...map.values()];return s;
  }
  function validate(s, {rebuildPositions=true}={}) {
    if(!s||s.version!==2)fail('Versi backup tidak didukung.');
    const tables=['users','workspaces','workspace_members','accounts','institutions','transactions','transaction_entries','categories','instruments','investment_transactions','market_prices','exchange_rates','currencies'];
    for(const key of tables)if(!Array.isArray(s[key])||s[key].length>50000)fail(`Tabel ${key} tidak valid.`);
    if(s.users.length!==1||s.workspaces.length!==1||s.workspace_members.length!==1)fail('Versi lokal mendukung satu pemilik dan satu workspace.');
    const uid=s.users[0].id,wid=s.workspaces[0].id;
    if(!text(uid,100)||!uid||!text(wid,100)||!wid||!text(s.users[0].name,80)||s.users[0].timezone!=='Asia/Jakarta'||s.users[0].default_currency!=='IDR'||s.workspaces[0].base_currency!=='IDR'||!text(s.workspaces[0].name,80)||s.workspace_members[0].user_id!==uid||s.workspace_members[0].workspace_id!==wid)fail('Identitas workspace tidak valid.');
    for(const key of ['accounts','institutions','transactions','transaction_entries','categories','instruments','investment_transactions']){
      const ids=new Set();for(const r of s[key]){if(!r||!text(r.id,100)||!r.id||ids.has(r.id))fail(`ID ganda / tidak valid di ${key}.`);ids.add(r.id);}
    }
    const aids=new Map(s.accounts.map(a=>[a.id,a])),iids=new Map(s.instruments.map(i=>[i.id,i])),tids=new Map(s.transactions.map(t=>[t.id,t])),cids=new Map(s.categories.map(c=>[c.id,c]));
    for(const i of s.institutions)if(!text(i.name,80)||!i.name.trim()||!['bank','broker','exchange','ewallet','other'].includes(i.type))fail('Institusi tidak valid.');
    for(const a of s.accounts)if(a.workspace_id!==wid||!types.includes(a.account_type)||!Object.hasOwn(currencies,a.currency)||!text(a.name,80)||!a.name.trim()||!text(a.note)||!['active','archived','closed'].includes(a.status)||(a.institution_id!==null&&!s.institutions.some(i=>i.id===a.institution_id)))fail('Data akun tidak valid.');
    for(const c of s.categories)if(c.workspace_id!==wid||!['income','expense'].includes(c.type)||!text(c.name,80)||!c.name.trim())fail('Kategori tidak valid.');
    for(const i of s.instruments)if(i.instrument_type!=='gold'||i.currency!=='IDR'||!text(i.name,80)||!i.name.trim()||!Number.isFinite(i.purity)||i.purity<=0||i.purity>100||!text(i.note))fail('Instrumen emas tidak valid.');
    const sums=new Map(),counts=new Map();
    for(const t of s.transactions)if(t.workspace_id!==wid||!['income','expense','transfer','investment','adjustment'].includes(t.transaction_type)||!timestamp(t.transaction_date)||!text(t.description,200)||t.created_by!==uid||(t.category_id!==null&&!cids.has(t.category_id)))fail('Transaksi tidak valid.');
    for(const e of s.transaction_entries){
      if(!tids.has(e.transaction_id)||!Object.hasOwn(currencies,e.currency)||!safe(e.amount_minor)||Math.abs(e.amount_minor)>1e14||(e.account_id!==null&&(!aids.has(e.account_id)||aids.get(e.account_id).currency!==e.currency))||(e.account_id===null&&!['equity','income','expense','fx','investment'].includes(e.offset_type)))fail('Entri ledger tidak valid.');
      const key=`${e.transaction_id}/${e.currency}`;sums.set(key,(sums.get(key)||0)+e.amount_minor);counts.set(e.transaction_id,(counts.get(e.transaction_id)||0)+1);
    }
    if([...sums.values()].some(n=>n!==0)||s.transactions.some(t=>(counts.get(t.id)||0)<2))fail('Ledger tidak seimbang.');
    for(const t of s.transactions){
      const entries=s.transaction_entries.filter(e=>e.transaction_id===t.id),real=entries.filter(e=>e.account_id!==null);
      if(t.category_id!==null&&cids.get(t.category_id).type!==t.transaction_type)fail('Kategori tidak cocok dengan transaksi.');
      if(['income','expense','adjustment'].includes(t.transaction_type)){
        const offset=entries.find(e=>e.account_id===null),kind=t.transaction_type==='adjustment'?'equity':t.transaction_type;
        if(entries.length!==2||real.length!==1||!offset||offset.offset_type!==kind||!real[0].amount_minor||t.transaction_type==='income'&&real[0].amount_minor<=0||t.transaction_type==='expense'&&real[0].amount_minor>=0)fail('Entri tidak cocok dengan jenis transaksi.');
      }
      if(t.transaction_type==='transfer'){
        const out=real.find(e=>e.amount_minor<0),incoming=real.find(e=>e.amount_minor>0);
        if(real.length!==2||!out||!incoming||out.account_id===incoming.account_id||entries.length!==(out.currency===incoming.currency?2:4)||entries.some(e=>e.account_id===null&&e.offset_type!=='fx'))fail('Ledger transfer tidak valid.');
      }
    }
    for(const t of s.investment_transactions){const a=aids.get(t.account_id),tx=tids.get(t.transaction_id);
      if(t.workspace_id!==wid||!a||a.account_type!=='investment'||!iids.has(t.instrument_id)||!tx||tx.transaction_type!=='investment'||t.transaction_date!==tx.transaction_date||!['opening','buy','sell'].includes(t.type)||t.currency!=='IDR'||!safe(t.quantity_micrograms)||t.quantity_micrograms<=0||(t.total_minor!==null&&(!safe(t.total_minor)||t.total_minor<0))||(t.type!=='opening'&&(!safe(t.total_minor)||t.total_minor<=0))||!safe(t.fee_minor)||t.fee_minor<0||!safe(t.tax_minor)||t.tax_minor<0)fail('Transaksi investasi tidak valid.');
      const entries=s.transaction_entries.filter(e=>e.transaction_id===tx.id),real=entries.filter(e=>e.account_id!==null);
      if(t.type==='opening'){if(real.length||t.fee_minor||t.tax_minor)fail('Saldo awal emas tidak boleh mengubah saldo fiat.');}
      else{const cash=aids.get(t.cash_account_id),net=t.type==='buy'?-(t.total_minor+t.fee_minor+t.tax_minor):t.total_minor-t.fee_minor-t.tax_minor;if(!cash||cash.currency!=='IDR'||real.length!==1||real[0].account_id!==cash.id||real[0].amount_minor!==net||t.type==='sell'&&net<0)fail('Ledger pembayaran emas tidak cocok.');}
    }
    for(const tx of s.transactions)if(tx.transaction_type==='investment'&&s.investment_transactions.filter(t=>t.transaction_id===tx.id).length!==1)fail('Transaksi investasi kehilangan detail.');
    for(const p of s.market_prices)if(!iids.has(p.instrument_id)||!Number.isFinite(p.price)||p.price<=0||p.currency!=='IDR'||!timestamp(p.timestamp)||!text(p.source,80))fail('Harga instrumen tidak valid.');
    for(const r of s.exchange_rates)if(r.base_currency!=='USD'||!Object.hasOwn(currencies,r.quote_currency)||!Number.isFinite(r.rate)||r.rate<=0||!timestamp(r.timestamp)||!text(r.source,80))fail('Kurs tidak valid.');
    if(s.market!==null){const m=s.market;if(!m||!['spot','manual'].includes(m.mode)||!timestamp(m.fetchedAt)||(m.goldIDR!==null&&(!Number.isFinite(m.goldIDR)||m.goldIDR<=0))||!m.rates||typeof m.rates!=='object'||Array.isArray(m.rates)||Object.values(m.rates).some(v=>!Number.isFinite(v)||v<=0)||(Object.keys(m.rates).length&&(m.rates.USD!==1||!m.rates.IDR))||(m.goldAt!==null&&!timestamp(m.goldAt))||(m.fxAt!==null&&!timestamp(m.fxAt)))fail('Cache pasar tidak valid.');}
    if(rebuildPositions)rebuild(s);
    const totals=balances(s);
    for(const a of s.accounts){if(!safe(totals[a.id]))fail('Saldo melebihi batas.');if(a.status!=='active'&&(totals[a.id]!==0||s.positions.some(p=>p.account_id===a.id&&p.quantity_micrograms)))fail('Akun nonaktif masih memiliki saldo / emas.');}
    return s;
  }
  function tx(s,type,description,date,category=null){const t={id:id(),workspace_id:s.workspaces[0].id,transaction_type:type,transaction_date:date||now(),description,category_id:category,source:'manual',created_by:s.users[0].id,created_at:now()};s.transactions.push(t);return t;}
  function entry(s,t,aid,amount,currency,offset){s.transaction_entries.push({id:id(),transaction_id:t.id,account_id:aid,amount_minor:amount,currency,offset_type:aid===null?offset:null});}
  function pair(s,t,aid,amount,offset){const a=account(s,aid);entry(s,t,aid,amount,a.currency);entry(s,t,null,-amount,a.currency,offset);}
  function createAccount(s,input){
    const n=clone(s),at=now(),a={id:id(),workspace_id:n.workspaces[0].id,parent_account_id:null,name:input.name.trim(),account_type:input.account_type,currency:input.currency,institution_id:null,status:'active',note:input.note||'',created_at:at,updated_at:at};
    if(input.institution?.trim()){let inst=n.institutions.find(i=>i.name===input.institution.trim());if(!inst){inst={id:id(),name:input.institution.trim(),type:a.account_type==='bank'?'bank':a.account_type==='ewallet'?'ewallet':a.account_type==='investment'?'broker':'other',country:'ID'};n.institutions.push(inst);}a.institution_id=inst.id;}
    n.accounts.push(a);const opening=minor(input.balance||0,a.currency);if(opening){const t=tx(n,'adjustment',`Saldo awal: ${a.name}`,input.date);pair(n,t,a.id,opening,'equity');}return validate(n);
  }
  function updateAccount(s,key,input){const n=clone(s),a=account(n,key);a.name=input.name.trim();a.note=input.note||'';a.updated_at=now();const target=minor(input.balance,a.currency),delta=target-balance(n,key);if(delta){const t=tx(n,'adjustment',`Koreksi saldo: ${a.name}`,now());pair(n,t,key,delta,'equity');}return validate(n);}
  function archive(s,key){const n=clone(s),a=account(n,key);if(balance(n,key)!==0||n.positions.some(p=>p.account_id===key&&p.quantity_micrograms))fail('Kosongkan saldo dan kepemilikan sebelum mengarsipkan akun.');a.status='archived';return validate(n);}
  function createGold(s,input){
    const n=clone(s),a=account(n,input.account_id);if(a.status!=='active'||a.account_type!=='investment')fail('Pilih akun investasi aktif.');
    const i={id:id(),instrument_type:'gold',name:input.name.trim(),symbol:'XAU',provider_symbol:'XAU',currency:'IDR',purity:Number(input.purity),note:input.note||'',created_at:now()};n.instruments.push(i);
    if(Number(input.grams)===0){if(input.cost!==''&&input.cost!==null&&Number(input.cost)!==0)fail('Instrumen tanpa kepemilikan tidak boleh memiliki modal.');return validate(n);}
    const t=tx(n,'investment',`Kepemilikan awal: ${i.name}`,input.date),cost=input.cost===''||input.cost===null?null:minor(input.cost,'IDR');
    entry(n,t,null,0,'IDR','equity');entry(n,t,null,0,'IDR','investment');
    n.investment_transactions.push({id:id(),transaction_id:t.id,workspace_id:n.workspaces[0].id,account_id:a.id,instrument_id:i.id,cash_account_id:null,type:'opening',quantity_micrograms:grams(input.grams),total_minor:cost,fee_minor:0,tax_minor:0,currency:'IDR',transaction_date:t.transaction_date});
    if(n.market?.goldIDR)n.market_prices.push({instrument_id:i.id,price:n.market.goldIDR*i.purity/100,currency:'IDR',timestamp:n.market.goldAt||n.market.fetchedAt,source:n.market.mode});
    return validate(n);
  }
  function transact(s,input){
    const n=clone(s),a=account(n,input.account_id);if(a.status!=='active')fail('Akun sudah diarsipkan.');
    let category=null;if(input.category_id){const c=n.categories.find(c=>c.id===input.category_id);if(!c||c.type!==input.type)fail('Kategori tidak cocok dengan jenis transaksi.');category=c.id;}
    if(input.new_category?.trim()){if(!['income','expense'].includes(input.type))fail('Kategori hanya untuk pemasukan / pengeluaran.');const c={id:id(),workspace_id:n.workspaces[0].id,name:input.new_category.trim(),type:input.type};n.categories.push(c);category=c.id;}
    const t=tx(n,['buy','sell'].includes(input.type)?'investment':input.type,input.description.trim(),input.date,category);
    if(['income','expense','adjustment'].includes(input.type)){const amount=minor(input.amount,a.currency);if(input.type==='adjustment'?amount===0:amount<=0)fail('Jumlah transaksi harus lebih dari nol.');pair(n,t,a.id,input.type==='expense'?-amount:amount,input.type==='adjustment'?'equity':input.type);}
    else if(input.type==='transfer'){
      const dest=account(n,input.destination_id),amount=minor(input.amount,a.currency);if(dest.id===a.id||dest.status!=='active'||amount<=0)fail('Pilih akun tujuan berbeda dan jumlah positif.');
      const received=a.currency===dest.currency?amount:minor(input.received,dest.currency);if(received<=0)fail('Isi jumlah yang diterima dalam mata uang tujuan.');
      entry(n,t,a.id,-amount,a.currency);entry(n,t,dest.id,received,dest.currency);if(a.currency!==dest.currency){entry(n,t,null,amount,a.currency,'fx');entry(n,t,null,-received,dest.currency,'fx');}
    }else if(['buy','sell'].includes(input.type)){
      const inv=account(n,input.investment_account_id),i=instrument(n,input.instrument_id);if(a.currency!=='IDR'||inv.status!=='active'||inv.account_type!=='investment')fail('Pembayaran emas memakai akun IDR dan akun investasi aktif.');
      const total=minor(input.amount,'IDR'),fee=minor(input.fee||0,'IDR'),tax=minor(input.tax||0,'IDR');if(total<=0||fee<0||tax<0||input.type==='sell'&&fee+tax>total)fail('Nilai, biaya, atau pajak tidak valid.');
      const quantity=grams(input.grams),net=input.type==='buy'?-(total+fee+tax):total-fee-tax;
      pair(n,t,a.id,net,'investment');n.investment_transactions.push({id:id(),transaction_id:t.id,workspace_id:n.workspaces[0].id,account_id:inv.id,instrument_id:i.id,cash_account_id:a.id,type:input.type,quantity_micrograms:quantity,total_minor:total,fee_minor:fee,tax_minor:tax,currency:'IDR',transaction_date:t.transaction_date});
    }else fail('Jenis transaksi tidak didukung.');
    return validate(n);
  }
  function migrate(old){
    if(!old||old.version!==1||!Array.isArray(old.assets)||old.assets.length>10000)fail('Backup lama tidak valid.');
    let s=empty();const ids=new Set(),at=now();
    for(const a of old.assets){if(!a||!text(a.id,100)||ids.has(a.id)||!text(a.name,80)||!a.name.trim()||!text(a.location,80)||!text(a.note)||!['fiat','gold'].includes(a.type))fail('Aset lama tidak valid.');ids.add(a.id);
      if(a.type==='fiat'){if(!Number.isFinite(a.balance)||a.balance<0)fail('Saldo lama tidak valid.');s=createAccount(s,{name:a.name,account_type:'bank',currency:a.currency,institution:a.location,note:a.note,balance:a.balance,date:at});}
      else{if(!Number.isFinite(a.grams)||a.grams<=0||!Number.isFinite(a.purity)||a.purity<=0||a.purity>100||a.cost!==null&&(!Number.isFinite(a.cost)||a.cost<0))fail('Emas lama tidak valid.');s=createAccount(s,{name:a.location||a.name,account_type:'investment',currency:'IDR',balance:0});s=createGold(s,{account_id:s.accounts.at(-1).id,name:a.name,purity:a.purity,grams:a.grams,cost:a.cost,note:a.note,date:at});}
    }
    s.market=old.market??null;return validate(s);
  }
  function read(data){return data?.version===1?migrate(data):validate(clone(data));}
  const api={currencies,types,minor,grams,empty,validate,read,clone,balance,balances,position,rebuild,createAccount,updateAccount,archive,createGold,transact};
  root.Finance=api;if(typeof module!=='undefined')module.exports=api;
})(typeof globalThis!=='undefined'?globalThis:this);
