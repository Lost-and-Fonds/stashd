// Bounded synthetic slow-reader probe; this is not a capacity benchmark.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {spawn, execFileSync} from 'node:child_process';
import {setTimeout as delay} from 'node:timers/promises';
const config=JSON.parse(await fs.readFile('tmp/mercure-spike/credentials.json','utf8'));
const token=execFileSync('lerd',['php','dev/mercure-spike/fixture.php','token','subscribe'],{encoding:'utf8'}).trim();
const clients=[];
let latest='';
try {
    for(let i=0;i<9;i++) {
        const child=spawn('curl',['-sSN','--max-time','90','-H','Authorization: Bearer '+token,config.public_url+'?topic='+encodeURIComponent(config.topic)],{stdio:['ignore','pipe','pipe']});
        const client={child,bytes:0,sawFinal:false,tail:''}; clients.push(client);
        if(i===0) child.stdout.pause();
        else child.stdout.on('data',chunk=>{client.bytes+=chunk.length;const text=client.tail+chunk.toString();if(text.includes('load-message-95'))client.sawFinal=true;client.tail=text.slice(-64);});
    }
    await delay(500);
    const before=execFileSync('podman',['stats','--no-stream','--format','{{.Name}} CPU={{.CPU}} MEM={{.MemUsage}}','lerd-stashd-mercure-spike'],{encoding:'utf8'}).trim();
    const started=Date.now();
    for(let i=0;i<96;i++) {
        latest='load-message-'+i;
        execFileSync('lerd',['php','dev/mercure-spike/fixture.php','publish',latest+'x'.repeat(65536)],{stdio:'pipe'});
    }
    await delay(1000);
    for(const client of clients.slice(1)) {assert(client.bytes>6_000_000);assert(client.sawFinal);}
    const http=execFileSync('curl',['-sS','-o','/dev/null','-w','HTTP %{http_code}; %{time_total}s','https://core.test/'],{encoding:'utf8'});
    assert(http.includes('HTTP 200'));
    const after=execFileSync('podman',['stats','--no-stream','--format','{{.Name}} CPU={{.CPU}} MEM={{.MemUsage}}','lerd-stashd-mercure-spike'],{encoding:'utf8'}).trim();
    const report={clients:9,unreadClients:1,messages:96,payloadBytes:65536,durationMs:Date.now()-started,fastClientBytes:clients.slice(1).map(c=>c.bytes),before,after,http,limit:'A bounded unread pipe probe; no sustained overload, timeout-disconnect or capacity guarantee.'};
    await fs.writeFile('tmp/mercure-spike/load-evidence.json',JSON.stringify(report,null,2));
    console.log(JSON.stringify(report,null,2));
} finally {for(const {child} of clients) child.kill('SIGTERM');}
