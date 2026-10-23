'use strict';
const byId = id => document.getElementById(id);
let users = [], visible = 100;
function render() {
  const query = byId('search').value.toLowerCase();
  const filtered = users.filter(u => u.username.toLowerCase().includes(query));
  byId('rows').replaceChildren();
  for (const u of filtered.slice(0, visible)) {
    const row = document.createElement('tr');
    [u.username, u.expires_at || 'Não informada', u.status, u.max_connections ?? 'Não informado', u.active_conns ?? 'Não informado'].forEach(v => {
      const cell = document.createElement('td'); cell.textContent = String(v); row.append(cell);
    });
    byId('rows').append(row);
  }
  if (!filtered.length) {
    const row = document.createElement('tr'), cell = document.createElement('td');
    cell.colSpan = 5; cell.textContent = 'Nenhuma conta encontrada para este filtro.'; row.append(cell); byId('rows').append(row);
  }
  byId('count').textContent = `${Math.min(visible, filtered.length)} de ${filtered.length} contas`;
  byId('more').hidden = visible >= filtered.length;
}
byId('search').addEventListener('input', () => { visible = 100; render(); });
byId('more').addEventListener('click', () => { visible += 100; render(); });
byId('refresh').addEventListener('click', async () => {
  const button = byId('refresh'); button.disabled = true; button.textContent = 'Consultando…'; byId('error').textContent = '';
  try {
    const response = await fetch('?users=1', {cache: 'no-store', credentials: 'same-origin'});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'Não foi possível consultar.');
    users = data.users; visible = 100;
    byId('total').textContent = users.length;
    byId('expired').textContent = users.filter(u => u.status.startsWith('vencido')).length;
    byId('online').textContent = users.some(u => Number.isInteger(u.active_conns)) ? users.filter(u => u.active_conns > 0).length : 'Não informado';
    byId('meta').textContent = `Fonte: ${data.source === 'linux' ? 'contas locais Linux' : 'API DragonCore'} • Coleta: ${new Date(data.collected_at).toLocaleString('pt-BR')}`;
    byId('warnings').replaceChildren();
    for (const warning of data.warnings || []) { const p = document.createElement('p'); p.className = 'notice'; p.textContent = warning; byId('warnings').append(p); }
    render();
  } catch (err) { byId('error').textContent = `${err.message} Os dados anteriores, se houver, não foram atualizados.`; }
  finally { button.disabled = false; button.textContent = '↻ Consultar agora'; }
});
