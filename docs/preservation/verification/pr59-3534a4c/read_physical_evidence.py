from pathlib import Path
import hashlib,json,sys

workspace=Path(sys.argv[1])
checks=[]
def require(condition,message):
    if not condition: raise AssertionError(message)
    checks.append(message)
def digest(data,algorithm='sha512'):
    return hashlib.new(algorithm,data).hexdigest()
def read(path): return json.loads(path.read_bytes())
def envelope(path):
    value=read(path)
    require(digest(value['json'].encode())==value['sha512'],str(path)+': envelope hashes original JSON')
    return json.loads(value['json']),value['sha512']
def inventories(root):
    require((root/'0=ocfl_1.1').read_text().strip()=='ocfl_1.1',str(root)+': OCFL 1.1 root marker')
    result={}
    for marker in root.rglob('0=ocfl_object_1.1'):
        folder=marker.parent
        require(marker.read_text().strip()=='ocfl_object_1.1',str(folder)+': Object marker')
        raw=(folder/'inventory.json').read_bytes()
        inv=json.loads(raw)
        require(inv['id'] not in result,'Object ID appears only once: '+inv['id'])
        algorithm=inv['digestAlgorithm']
        require(algorithm in ('sha512','sha256'),'Recognized OCFL digest algorithm')
        versions=inv['versions']
        require(list(versions)==['v'+str(i) for i in range(1,len(versions)+1)],'Contiguous version history: '+inv['id'])
        require(inv['head']=='v'+str(len(versions)),'Head matches final version: '+inv['id'])
        require(raw==(folder/inv['head']/'inventory.json').read_bytes(),'Root inventory equals acknowledged head inventory: '+inv['id'])
        for inventory_path in [folder/'inventory.json',*[folder/v/'inventory.json' for v in versions]]:
            body=inventory_path.read_bytes()
            tokens=(Path(str(inventory_path)+'.'+algorithm)).read_text().split()
            require(tokens==[digest(body,algorithm),'inventory.json'],'Exact inventory checksum sidecar: '+str(inventory_path))
            historical=json.loads(body)
            require(historical['id']==inv['id'],'Historical identity: '+str(inventory_path))
            for version,entry in historical['versions'].items():
                require(entry==versions[version],'Historical version entry immutable: '+str(inventory_path)+' '+version)
                for key,names in entry['state'].items():
                    require(bool(names) and key in historical['manifest'],'Historical state is manifest-backed')
            for key,paths in historical['manifest'].items():
                for relative in paths:
                    p=(folder/relative).resolve()
                    require(p.is_relative_to(folder.resolve()),'Manifest path stays inside Object')
                    require(digest(p.read_bytes(),algorithm)==key,'Manifest payload digest: '+str(p))
        result[inv['id']]=(folder,inv)
    return result

def payload(objects,object_id,version,name):
    folder,inv=objects[object_id]
    matches=[key for key,names in inv['versions'][version]['state'].items() if name in names]
    require(len(matches)==1,'Exact logical payload exists once: '+object_id+' '+version+' '+name)
    return (folder/inv['manifest'][matches[0]][0]).read_bytes()

def transaction(root,objects,tx,expect_complete=True):
    bucket=root/'extensions/stashd-transactions'/digest(tx.encode(),'sha256')
    raw=(bucket/'candidate.json').read_bytes()
    seal=(bucket/'candidate.sha512').read_text()
    require(digest(raw)==seal,tx+': candidate seal matches physical bytes')
    candidate=json.loads(raw)
    require(candidate['delta']['id']==tx,tx+': candidate ID')
    require(candidate['vault']==read(root/'identity.json')['identifier'],tx+': candidate Vault identity')
    plan,plan_hash=envelope(bucket/'plan.json')
    require(plan['candidate']==seal,tx+': plan binds candidate')
    binding={'id':tx,'candidate':seal,'plan':plan_hash}
    for step in [plan['item'],*plan['assets'].values()]:
        if step['object_id'] not in objects:
            require(not expect_complete,tx+': absent Object only before completion')
            continue
        if step['version'] not in objects[step['object_id']][1]['versions']:
            require(not expect_complete,tx+': absent version only before completion')
            continue
        name='item.json' if step is plan['item'] else '.stashd/asset.json'
        record=json.loads(payload(objects,step['object_id'],step['version'],name))
        require(record['transaction']==binding,tx+': exact Item/Asset binding '+step['object_id'])
    for key,step in plan['assets'].items():
        if step['object_id'] not in objects: continue
        body=payload(objects,step['object_id'],step['version'],'data.bin')
        accepted=candidate['delta']['changes'][key]
        require(digest(body)==accepted['digest'] and len(body)==accepted['size'],tx+': accepted Asset digest and size '+key)
        if (bucket/accepted['file']).exists():
            require((bucket/accepted['file']).read_bytes()==body,tx+': retained custody equals OCFL '+key)
    if expect_complete:
        completion,_=envelope(bucket/'complete.json')
        require(completion=={'status':'complete','transaction_id':tx,'item':{'object_id':plan['item']['object_id'],'version':plan['item']['version']}},tx+': exact completion receipt')
        item=json.loads(payload(objects,plan['item']['object_id'],plan['item']['version'],'item.json'))
        require(item['metadata']==plan['metadata'] and item['pins']==plan['pins'],tx+': complete Item metadata and pins equal plan')
        require(item['uri']==candidate['delta']['uri'],tx+': canonical Item URI')
        for pin in item['pins']:
            asset=json.loads(payload(objects,pin['object_id'],pin['version'],'.stashd/asset.json'))
            require(asset['item_uri']==item['uri'] and asset['item_object_id']==item['object_id'],tx+': every exact Asset pin belongs to Item')
            payload(objects,pin['object_id'],pin['version'],'data.bin')
        if (bucket/'custody-release.json').exists():
            authorization,_=envelope(bucket/'custody-release.json')
            require(authorization=={'completion':completion,'candidate':seal},tx+': release authorization binds verified completion')
    return bucket,candidate,plan

def snapshot(root):
    return {'/'+str(p.relative_to(root)):digest(p.read_bytes()) for p in root.rglob('*') if p.is_file()}

stages={p.name:p for p in (workspace/'stages').iterdir()}
objects={name:inventories(root) for name,root in stages.items()}
tx='independent-3534a4c'
for stage in ['commit-crash','complete','replay','release-crash','released','released-replay','lost-ack','lost-ack-replay','numeric']:
    transaction(stages[stage],objects[stage],tx,stage!='commit-crash')
require(len(objects['commit-crash'])==1,'After master SIGKILL: exactly one Asset, no Item or sidecar Object')
crash_bucket=stages['commit-crash']/'extensions/stashd-transactions'/digest(tx.encode(),'sha256')
require(not (crash_bucket/'complete.json').exists(),'After master SIGKILL: no completion journal')
require(all((crash_bucket/f'custody-{i}').exists() for i in [0,1]),'After master SIGKILL: both custody copies remain')
for name in ['commit-crash','release-crash','lost-ack-crash']:
    killed=read(workspace/(name+'.json'))
    require(killed['signal']==9 and killed['out']=='',name+': actual SIGKILL, no success reply')
for name in ['recovered','replayed','release-crash-recovered','released-replayed','released-recovered']:
    require(read(workspace/(name+'.json'))==read(workspace/'recovered.json'),name+': exact receipt reused')
for a,b in [('complete','replay'),('released','released-replay'),('lost-ack','lost-ack-replay')]:
    require(snapshot(stages[a])==snapshot(stages[b]),a+' -> '+b+': every Vault byte unchanged')
complete=snapshot(stages['complete']); released=snapshot(stages['released'])
bucket='/extensions/stashd-transactions/'+digest(tx.encode(),'sha256')
require(set(complete)-set(released)=={bucket+'/custody-0',bucket+'/custody-1'},'Only redundant custody files removed')
require(set(released)-set(complete)=={bucket+'/custody-release.json'},'Only release authorization added')
require(all(released[p]==h for p,h in complete.items() if p in released),'All permanent journal and OCFL bytes unchanged by release')
release_bucket=stages['release-crash']/bucket.lstrip('/')
require(not (release_bucket/'custody-0').exists() and (release_bucket/'custody-1').exists(),'Release SIGKILL interrupts between two actual deletions')
for name in ['complete','replay','release-crash','released','released-replay']:
    require(len(objects[name])==3,name+': one Item plus two Assets')
    require(all(list(inv['versions'])==['v1'] for _,inv in objects[name].values()),name+': exactly one version per Object')
for name in ['lost-ack','lost-ack-replay','numeric']:
    transaction(stages[name],objects[name],'independent-lost-ack')
_,_,lost_plan=transaction(stages['lost-ack'],objects['lost-ack'],'independent-lost-ack')
require(lost_plan['item']['version']=='v2' and lost_plan['assets']['sidecar']['version']=='v2','Changed Item and sidecar advance exactly once')
require(sorted(pin['version'] for pin in lost_plan['pins'])==['v1','v2'],'Unchanged master remains pinned to v1 after sidecar update')
require(read(workspace/'lost-ack-recovered.json')==read(workspace/'lost-ack-replayed.json'),'Lost acknowledgement replays exact receipt')
num_bucket,num_candidate,num_plan=transaction(stages['numeric'],objects['numeric'],'independent-numeric')
for values in [num_candidate['delta']['metadata'],num_plan['metadata']]:
    require(type(values['measurement']) is float and values['measurement']==1.0 and type(values['nested']['value']) is float and values['nested']['value']==2.0 and type(values['zero']) is int,'Native float/integer JSON types preserved in numeric candidate and plan')
require('1.0' in read(num_bucket/'plan.json')['json'] and '2.0' in read(num_bucket/'plan.json')['json'],'Plan hash uses original fraction-preserving JSON bytes')
for name,root in stages.items():
    (workspace/(name+'-independent-manifest.json')).write_text(json.dumps(snapshot(root),indent=2,sort_keys=True)+'\n')
summary={'decision':'PASS','checks':len(checks),'stage_object_counts':{name:len(value) for name,value in objects.items()},'scenarios':['partial multi-Object commit SIGKILL and source deletion','exact-version journal/OCFL binding','fresh-process recovery and idempotent replay','custody-release SIGKILL and replay after custody deletion','lost acknowledgement after complete journal','fraction-preserving native numeric metadata']}
(workspace/'physical-verification.json').write_text(json.dumps(summary,indent=2)+'\n')
(workspace/'physical-checks.txt').write_text('\n'.join(checks)+'\n')
print(json.dumps(summary,indent=2))
