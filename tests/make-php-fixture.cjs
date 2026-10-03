const F=require('../model.js'),fs=require('node:fs');
const at='2026-10-03T00:00:00Z';
let s=F.createAccount(F.empty(),{name:'Test bank',currency:'IDR',account_type:'bank',balance:20000000,date:at});const cash=s.accounts[0].id;
s=F.createAccount(s,{name:'Test safe',currency:'IDR',account_type:'investment',balance:0});const inv=s.accounts[1].id;
s=F.createGold(s,{name:'Test gold',account_id:inv,purity:99.99,grams:0,cost:''});const instr=s.instruments[0].id;
for(const t of [{type:'buy',grams:10,amount:10000000,fee:100000,tax:0,date:at},{type:'sell',grams:4,amount:5000000,fee:50000,tax:10000,date:'2026-10-03T01:00:00Z'}])s=F.transact(s,{...t,account_id:cash,investment_account_id:inv,instrument_id:instr,description:t.type});
fs.writeFileSync(process.argv[2],JSON.stringify(s));
