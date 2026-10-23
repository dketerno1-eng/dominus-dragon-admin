'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
class Element {
  constructor() { this.value=''; this.children=[]; this.listeners={}; this.classList={toggle(){}}; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children=nodes; }
  setAttribute() {}
  close() {}
  showModal() {}
  reset() {}
}
class FormDataFixture {
  constructor(target) { this.values = new Map(Object.entries(target?.fields || {})); }
  set(key,value) { this.values.set(key,value); }
  get(key) { return this.values.get(key); }
}
const source=fs.readFileSync(path.join(__dirname,'../assets/app.js'),'utf8');
const fixtures=[{id:'a',name:'Máquina A',category:'Testes',url:'http://example.com:9090',username:'admin'}, {id:'b',name:'Máquina B',category:'Clientes',url:'http://example.org:9090',username:'admin'}];
function setup(servers=fixtures) {
  const elements=new Map(), requests=[];
  const element=id => {if(!elements.has(id)) elements.set(id,new Element());return elements.get(id);};
  element('server-data').value=JSON.stringify(servers);element('csrf').value='csrf-fixture';
  const context=vm.createContext({document:{getElementById:element,createElement:()=>new Element()},FormData:FormDataFixture,
    AbortController,setTimeout,clearTimeout,console,confirm:()=>true,
    fetch:(_,options)=>new Promise(resolve=>requests.push({form:options.body,resolve})),});
  vm.runInContext(source,context);
  return {context,requests,element};
}
const result=(id,total=1)=>({ok:true,users:[{username:id,expires_at:'2030-06-20T01:48:00Z',status:'valid',max_connections:0,active_conns:0}],summary:{total,valid:total,expired:0,connections_known:true,active_accounts:0},collected_at:'2026-10-23T12:00:00Z'});
const respond=(request,data,status=200)=>request.resolve({ok:status===200,json:async()=>data});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  const app=setup();
  assert.equal(app.requests.length,1,'abertura inicia uma consulta sem clique');
  assert.equal(app.requests[0].form.get('id'),'a');
  assert.equal(app.element('query').disabled,true);
  vm.runInContext("select('b'); select('a'); select('b');",app.context);
  assert.equal(app.requests.length,2,'troca consulta a outra máquina e deduplica as pendentes');
  respond(app.requests[1],result('b',2));await tick();
  assert.equal(app.element('selected-name').textContent,'Máquina B');
  assert.equal(app.element('total').textContent,2);
  respond(app.requests[0],result('a',1));await tick();
  assert.equal(app.element('total').textContent,2,'resposta antiga não altera a máquina selecionada');
  assert.equal(app.element('query').textContent,'↻ Atualizar agora');
  vm.runInContext("select('a'); select('b');",app.context);
  assert.equal(app.requests.length,2,'troca rápida reutiliza consulta recente');
  vm.runInContext("lastStarted.delete('b'); queryMachine('b');",app.context);
  assert.equal(app.requests.length,3);
  respond(app.requests[2],{ok:false,message:'VPS indisponível'},502);await tick();
  assert.equal(app.element('total').textContent,2,'falha preserva dados anteriores');
  assert.equal(app.element('message').textContent,'VPS indisponível');
  assert.match(app.element('updated').textContent,/atualização falhou/);
  const empty=setup([]);assert.equal(empty.requests.length,0,'sem máquinas não faz consulta');
  const first=setup();respond(first.requests[0],result('a'));await tick();
  const saved={...fixtures[0],id:'c',name:'Máquina C'};
  first.element('machine-form').fields={name:saved.name};
  const submit=first.element('machine-form').listeners.submit[0]({preventDefault(){},target:first.element('machine-form')});
  assert.equal(first.requests[1].form.get('action'),'save');
  respond(first.requests[1],{ok:true,servers:[...fixtures,saved],saved_id:'c'});await tick();await submit;
  assert.equal(first.requests[2].form.get('action'),'query','salvar máquina inicia consulta');
  assert.equal(first.requests[2].form.get('id'),'c');
  respond(first.requests[2],result('c'));await tick();
  console.log('OK: abertura automática, troca de máquina, deduplicação, respostas fora de ordem, falha sem perda de dados, lista vazia e consulta após salvar.');
})().catch(error=>{console.error(error);process.exitCode=1;});
