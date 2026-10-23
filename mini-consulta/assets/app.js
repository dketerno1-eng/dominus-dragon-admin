'use strict';
const $ = id => document.getElementById(id);
let servers = JSON.parse($('server-data').value), selected = null, busy = false, limit = 100;
const results = new Map(), failures = new Map();
const labels = {valid:'Vigente', expired:'Vencido', unknown:'Não informado'};
function message(text, error = false) { const el = $('message'); el.textContent = text; el.hidden = !text; el.classList.toggle('error', error); }
async function request(form) {
  form.set('csrf', $('csrf').value);
  const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 30000);
  try {
    const response = await fetch('./', {method:'POST', body:form, credentials:'same-origin', cache:'no-store', signal:controller.signal});
    let data;
    try { data = await response.json(); } catch { throw new Error('A hospedagem não retornou JSON. Atualize a página e confira a sessão.'); }
    if (!response.ok || !data.ok) throw new Error(data.message || 'Falha na operação.');
    return data;
  } catch (error) { if (error.name === 'AbortError') throw new Error('A consulta excedeu 30 segundos. Confira endereço e conectividade da VPS.'); throw error; }
  finally { clearTimeout(timeout); }
}
function listMachines() {
  $('machine-count').textContent = servers.length;
  const query = $('machine-search').value.toLowerCase(); $('machines').replaceChildren();
  const matches = servers.filter(s => `${s.name} ${s.category}`.toLowerCase().includes(query));
  for (const server of matches) {
    const button = document.createElement('button'); button.className = 'machine' + (selected === server.id ? ' selected' : '');
    button.type = 'button'; button.setAttribute('aria-pressed', String(selected === server.id));
    const name = document.createElement('strong'); name.textContent = server.name;
    const detail = document.createElement('span'); detail.textContent = server.category;
    const status = document.createElement('small'); status.textContent = failures.has(server.id) ? 'Última consulta falhou' : results.has(server.id) ? 'Consulta realizada' : 'Ainda não consultada';
    button.append(name,detail,status); button.addEventListener('click', () => select(server.id)); $('machines').append(button);
  }
  if (!matches.length) { const p = document.createElement('p'); p.className = 'side-note'; p.textContent = servers.length ? 'Nenhuma máquina neste filtro.' : 'Sua primeira máquina aparece aqui.'; $('machines').append(p); }
}
function select(id) { selected = id; limit = 100; $('user-search').value = ''; $('status-filter').value = 'all'; listMachines(); render(); }
function dateText(raw) {
  if (!raw) return 'Não informada';
  if (!/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/.test(raw)) return raw;
  const d = new Date(raw); return Number.isNaN(d.valueOf()) ? raw : d.toLocaleString('pt-BR');
}
function render() {
  const server = servers.find(s => s.id === selected), data = results.get(selected);
  $('selected-name').textContent = server?.name || 'Pronto para consultar';
  $('selected-category').textContent = server?.category || 'SELECIONE UMA MÁQUINA';
  $('selected-url').textContent = server ? `${server.url} • admin: ${server.username}` : 'Adicione a primeira VPS para começar.';
  $('edit').disabled = !server || busy; $('query').disabled = !server || busy;
  for (const key of ['total','valid','expired']) $(''+key).textContent = data ? data.summary[key] : '—';
  $('online').textContent = data?.summary.connections_known ? data.summary.active_accounts : '—';
  $('rows').replaceChildren();
  const search = $('user-search').value.toLowerCase(), status = $('status-filter').value;
  const filtered = (data?.users || []).filter(u => u.username.toLowerCase().includes(search) && (status === 'all' || status === u.status));
  for (const u of filtered.slice(0,limit)) {
    const row = document.createElement('tr');
    [u.username,dateText(u.expires_at),labels[u.status],u.max_connections ?? '—',u.active_conns ?? '—'].forEach((value,i) => {
      const cell = document.createElement('td'); cell.textContent = String(value); if(i===2)cell.className = 'state '+u.status; row.append(cell);
    }); $('rows').append(row);
  }
  if (!filtered.length) {
    const row=document.createElement('tr'), cell=document.createElement('td'); cell.colSpan=5;cell.className='empty';
    cell.textContent = data ? 'Nenhum usuário encontrado para este filtro.' : 'Clique em Consultar usuários para ler a API da máquina.'; row.append(cell);$('rows').append(row);
  }
  $('row-count').textContent = data ? `${Math.min(limit,filtered.length)} de ${filtered.length} usuários` : 'Sem consulta';
  $('more').hidden = !data || limit >= filtered.length;
  $('updated').textContent = data ? `Coleta: ${dateText(data.collected_at)}${failures.has(selected) ? ' • dados anteriores; atualização falhou' : ''}` : 'Nenhuma consulta realizada.';
  message(failures.get(selected) || '',failures.has(selected));
}
function edit(server = null) {
  $('machine-form').reset(); $('machine-id').value = server?.id || '';
  $('machine-name').value = server?.name || ''; $('machine-category').value = server?.category || 'Testes';
  $('machine-url').value = server?.url || ''; $('machine-username').value = server?.username || 'admin';
  $('allow-http').checked = Boolean(server?.url.startsWith('http://')); $('machine-password').required = !server;
  $('editor-title').textContent = server ? 'Editar máquina' : 'Adicionar máquina'; $('remove').hidden = !server;
  $('editor-error').hidden = true; $('editor').showModal();
}
$('machine-search').addEventListener('input',listMachines);
$('user-search').addEventListener('input',() => {limit=100;render();});
$('status-filter').addEventListener('change',() => {limit=100;render();});
$('more').addEventListener('click',()=>{limit+=100;render();});
$('add').addEventListener('click',()=>edit()); $('edit').addEventListener('click',()=>edit(servers.find(s=>s.id===selected)));
$('close-editor').addEventListener('click',()=>$('editor').close());
$('machine-url').addEventListener('input',()=>{ if($('machine-url').value.startsWith('https://'))$('allow-http').checked=false; });
$('machine-form').addEventListener('submit',async event=>{
  event.preventDefault(); const form = new FormData(event.target); form.set('action','save'); $('save').disabled=true; $('remove').disabled=true; $('editor-error').hidden=true;
  try { const data=await request(form);servers=data.servers;results.delete(data.saved_id);failures.delete(data.saved_id);$('editor').close();select(data.saved_id);message('Máquina salva. Clique em Consultar usuários para testar login e leitura.'); }
  catch(error){$('editor-error').textContent=error.message;$('editor-error').hidden=false;}
  finally{$('save').disabled=false;$('remove').disabled=false;}
});
$('remove').addEventListener('click',async()=>{
  if(!confirm('Remover somente o cadastro desta máquina do mini painel? Os usuários da VPS não serão alterados.'))return;
  const id=$('machine-id').value,form=new FormData();form.set('action','remove');form.set('id',id);$('save').disabled=true;$('remove').disabled=true;
  try{const data=await request(form);servers=data.servers;results.delete(id);failures.delete(id);$('editor').close();select(servers[0]?.id || null);message('Máquina removida do mini painel.');}
  catch(error){$('editor-error').textContent=error.message;$('editor-error').hidden=false;}
  finally{$('save').disabled=false;$('remove').disabled=false;}
});
$('query').addEventListener('click',async()=>{
  const id=selected;if(!id)return;busy=true;render();$('query').textContent='Consultando…';
  const form=new FormData();form.set('action','query');form.set('id',id);
  try{const data=await request(form);results.set(id,data);failures.delete(id);}
  catch(error){failures.set(id,error.message);}
  finally{busy=false;$('query').textContent='↻ Consultar usuários';listMachines();render();}
});
listMachines(); if(servers.length) select(servers[0].id); else render();
