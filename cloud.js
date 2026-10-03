'use strict';
// Hosted sites use PHP; local previews retain the existing device-local mode.
const CloudStore=(()=>{
  const local=location.protocol==='file:'||['localhost','127.0.0.1','[::1]'].includes(location.hostname);
  const enabled=!local||new URLSearchParams(location.search).get('backend')==='php';
  let csrf='',revision=0;
  async function request(action,method='GET',body){
    const response=await fetch(`api/index.php?action=${action}`,{method,credentials:'same-origin',headers:{'Accept':'application/json',...(body?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},...(body?{body:JSON.stringify(body)}:{}),signal:AbortSignal.timeout(25000),cache:'no-store'});
    let data;try{data=await response.json();}catch{throw Error('API PHP belum tersedia. Pastikan folder api ikut terdeploy dan hosting menjalankan PHP.');}
    if(!response.ok){const error=Error(data.error||'Permintaan server gagal.');error.status=response.status;throw error;}
    if(data.csrf)csrf=data.csrf;return data;
  }
  return {enabled,session:()=>request('session'),login:(data,setup)=>request(setup?'setup':'login','POST',data),logout:()=>request('logout','POST',{}),
    async load(){const data=await request('state');revision=data.revision;return data.state;},
    async save(state){const data=await request('state','PUT',{state,revision});revision=data.revision;return data.state;}};
})();
