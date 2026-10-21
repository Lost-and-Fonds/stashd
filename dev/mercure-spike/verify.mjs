// Opt-in live proof. Run on the host after setup, with an isolated CDP browser.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {setTimeout as delay} from 'node:timers/promises';

const config = JSON.parse(await fs.readFile('tmp/mercure-spike/credentials.json', 'utf8'));
const report = {checks: [], responses: [], errors: [], deliveryMs: []};
const php = (...args) => execFileSync('lerd', ['php', 'dev/mercure-spike/fixture.php', ...args], {encoding:'utf8'}).trim();
const token = (action, topic=config.topic, ttl='300') => php('token', action, topic, ttl);
const publish = message => JSON.parse(php('publish', message));
function curlStatus(path, args=[]) {
    return Number(execFileSync('curl', ['-sS', '--max-time', '3', '-o', '/dev/null', '-w', '%{http_code}', ...args, 'https://core.test'+path], {encoding:'utf8'}));
}
function check(name, fn) { fn(); report.checks.push(name); }
async function until(fn, description) {
    for (let i=0;i<100;i++) { if (await fn()) return; await delay(100); }
    throw Error('Timeout: '+description);
}
async function browser() {
    const target = await (await fetch('http://127.0.0.1:9339/json/new?https://core.test/', {method:'PUT'})).json();
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, {once:true}));
    let seq=0;
    const pending=new Map();
    ws.addEventListener('message', ({data}) => {
        const m=JSON.parse(data);
        if (m.id) {
            const p=pending.get(m.id); pending.delete(m.id);
            m.error ? p.reject(m.error) : p.resolve(m.result);
        }
        if (m.method==='Runtime.exceptionThrown') report.errors.push(m.params.exceptionDetails.text);
        if (m.method==='Network.responseReceived' && m.params.response.url.startsWith('https://core.test/')) {
            const r=m.params.response;
            report.responses.push({url:r.url,status:r.status,contentType:r.mimeType,rid:Object.entries(r.headers).find(([k])=>k.toLowerCase()==='x-lerd-rid')?.[1]});
        }
    });
    const call=(method,params={})=>new Promise((resolve,reject)=>{const id=++seq;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
    const evaluate=async expression=>{
        const result=await call('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
        if(result.exceptionDetails) throw Error(result.exceptionDetails.text);
        return result.result.value;
    };
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable');
    await until(()=>evaluate('location.origin === "https://core.test" && document.readyState === "complete"'), 'initial navigation');
    await evaluate(`fetch('/mercure-spike.php', {method:'POST',body:new URLSearchParams({password:${JSON.stringify(config.password)}})}).then(async r=>{if(r.status!==200)throw Error('login '+r.status);const html=await r.text();document.open();document.write(html);document.close();})`);
    await until(()=>evaluate('window.opens > 0'), 'EventSource open');
    return {call,evaluate,close:async()=>{await evaluate('source.close()');ws.close();await fetch('http://127.0.0.1:9339/json/close/'+target.id);}};
}

const boots=[];
for(let i=0;i<5;i++) {
    boots.push(JSON.parse(execFileSync('curl',['-fsS','--data-binary','@-','https://core.test/mercure-spike.php'],{encoding:'utf8',input:new URLSearchParams({password:config.password,probe:'1'}).toString()})));
}
check('five requests use fresh PHP state and nonpersistent Tempest kernels', ()=>{
    assert(boots.every(b=>b.requests===1 && b.long_running===false));
    assert.equal(new Set(boots.map(b=>b.tempest_start)).size,5);
});
report.boots=boots;
const subscription='/.well-known/mercure?topic='+encodeURIComponent(config.topic);
check('anonymous subscriber rejected', ()=>assert.equal(curlStatus(subscription),401));
check('anonymous publisher rejected', ()=>assert.equal(curlStatus('/.well-known/mercure',['-d','topic='+config.topic]),401));
check('invalid signature rejected', ()=>assert.equal(curlStatus(subscription,['-H','Authorization: Bearer '+token('subscribe')+'x']),401));
check('expired subscriber rejected', ()=>assert.equal(curlStatus(subscription,['-H','Authorization: Bearer '+token('subscribe',config.topic,'-60')]),401));
check('subscriber cannot publish', ()=>assert.equal(curlStatus('/.well-known/mercure',['-H','Authorization: Bearer '+token('subscribe'),'-d','topic='+config.topic]),401));
check('publisher cannot publish another private topic', ()=>assert.equal(curlStatus('/.well-known/mercure',['-H','Authorization: Bearer '+token('publish'),'-d','topic=https://core.test/spike/other&private=on&data=forbidden']),401));
check('publisher cannot publish another public topic', ()=>assert.equal(curlStatus('/.well-known/mercure',['-H','Authorization: Bearer '+token('publish'),'-d','topic=https://core.test/spike/other&data=forbidden']),401));
check('subscription API and debugger hidden', ()=>{
    assert.equal(curlStatus('/.well-known/mercure/subscriptions'),404);
    assert.equal(curlStatus('/.well-known/mercure/ui/'),404);
});
check('unsigned token rejected', ()=>assert.equal(curlStatus(subscription,['-H','Authorization: Bearer eyJhbGciOiJub25lIn0.e30.']),401));
const preflight=execFileSync('curl',['-sS','-D','-','-o','/dev/null','-X','OPTIONS','-H','Origin: https://untrusted.example','-H','Access-Control-Request-Method: GET',config.public_url],{encoding:'utf8'});
check('untrusted origin has no CORS permission', ()=>assert(!/access-control-allow-origin:/i.test(preflight)));
const a=await browser();
const b=await browser();
try {
    const cookies=await a.call('Network.getCookies',{urls:[config.public_url]});
    check('host-only, secure, HttpOnly, path-scoped subscriber cookie', ()=>{
        const c=cookies.cookies.find(c=>c.name==='mercureAuthorization');
        assert(c); assert(c.secure); assert(c.httpOnly); assert.equal(c.sameSite,'Strict');
        assert.equal(c.path,'/.well-known/mercure'); assert.equal(c.domain,'core.test');
    });
    await b.evaluate('window.forbidden=[]; window.wrong=new EventSource("/.well-known/mercure?topic=https%3A%2F%2Fcore.test%2Fspike%2Fsynthetic");wrong.onmessage=e=>forbidden.push(e.data)');
    // This additional stream has a valid token for another topic, never a wildcard.
    await b.call('Network.setCookie',{name:'mercureAuthorization',value:token('subscribe','https://core.test/spike/other'),url:config.public_url,path:'/.well-known/mercure',secure:true,httpOnly:true,sameSite:'Strict'});
    await b.evaluate('wrong.close();wrong=new EventSource("/.well-known/mercure?topic=https%3A%2F%2Fcore.test%2Fspike%2Fsynthetic");wrong.onmessage=e=>forbidden.push(e.data)');
    await delay(300);
    // Existing authorized streams retain their initial authorization.
    const started=Date.now(); const first=publish('two-subscribers');
    await until(async()=>await a.evaluate(`received.some(e=>e.id===${JSON.stringify(first.id)})`) && await b.evaluate(`received.some(e=>e.id===${JSON.stringify(first.id)})`), 'both subscribers receive CLI event');
    report.deliveryMs.push(await a.evaluate(`received.find(e=>e.id===${JSON.stringify(first.id)}).at`)-started);
    report.checks.push('two browsers receive identical private CLI event while connections remain open');
    await delay(300);
    assert.deepEqual(await b.evaluate('forbidden'),[]);
    report.checks.push('wrong-topic token receives no private event');
    await b.evaluate('wrong.close()');
    await b.call('Network.setCookie',{name:'mercureAuthorization',value:token('subscribe'),url:config.public_url,path:'/.well-known/mercure',secure:true,httpOnly:true,sameSite:'Strict'});
    await a.evaluate('source.close()');
    const missed=publish('missed-during-disconnect');
    await a.evaluate(`window.replayed=[];window.replay=new EventSource('/.well-known/mercure?topic='+encodeURIComponent(${JSON.stringify(config.topic)})+'&lastEventID='+encodeURIComponent(${JSON.stringify(first.id)}));replay.onmessage=e=>replayed.push(e.lastEventId)`);
    await until(()=>a.evaluate(`replayed.includes(${JSON.stringify(missed.id)})`),'retained history replay');
    await a.evaluate('replay.close();connect()');
    await until(()=>a.evaluate('opens>=2'),'manual reconnect');
    report.checks.push('disconnect, retained update replay, and manual reconnect');
    execFileSync('lerd',['restart'],{stdio:'pipe'});
    const after=publish('after-php-restart');
    await until(async()=>await a.evaluate(`received.some(e=>e.id===${JSON.stringify(after.id)})`) && await b.evaluate(`received.some(e=>e.id===${JSON.stringify(after.id)})`),'PHP restart independent delivery');
    report.checks.push('PHP runtime restart preserves active hub subscriptions and CLI publishing');
    const opens=await a.evaluate('opens');
    execFileSync('lerd',['service','restart','stashd-mercure-spike'],{stdio:'pipe'});
    await until(()=>a.evaluate(`opens>${opens}`),'native EventSource reconnect after hub restart');
    const restarted=publish('after-hub-restart');
    await until(()=>a.evaluate(`received.some(e=>e.id===${JSON.stringify(restarted.id)})`),'delivery after hub restart');
    report.checks.push('native EventSource reconnect and delivery after hub restart');
    // Stay subscribed beyond nginx's ordinary 60 second request timeout.
    await delay(65000);
    const long=publish('after-65-seconds');
    await until(()=>a.evaluate(`received.some(e=>e.id===${JSON.stringify(long.id)})`),'long-lived subscription');
    report.checks.push('subscription remains usable beyond 60 seconds with heartbeats');
    for(let i=0;i<5;i++) assert.equal(curlStatus('/'),200);
    report.checks.push('ordinary Vault HTTP requests succeed during SSE subscriptions');
    assert.equal(report.errors.length,0);
    assert(report.responses.some(r=>r.contentType==='text/event-stream'&&r.status===200));
    await fs.writeFile('tmp/mercure-spike/evidence.json',JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
} finally {
    await a.close(); await b.close();
}
