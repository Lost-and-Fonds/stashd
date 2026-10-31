from pathlib import Path
import subprocess,os,json
root=Path(__file__).resolve().parents[1];os.chdir(root)
out=root/'docs/development/verification/ops-c03';out.mkdir(parents=True,exist_ok=True)
runtime=Path('app/Operations/JobRuntime.php');authority=Path('app/Operations/AttemptAuthority.php');host=Path('app/Operations/ExecutionArtifacts.php')
def remove_checks(s):
 a=s.index('AND j.claim_token = ?');b=s.index(' RETURNING j.lease_until',a)
 return s[:a]+'AND ?::text IS NOT NULL'+s[b:]
def detach(s):
 s=s.replace("$database->fetchOne('SELECT id FROM jobs WHERE id = ? FOR UPDATE', [$context->jobId]);",'')
 needle='$this->requireCurrent($context);';pos=s.index(needle,s.index('$result = $mutation'))
 return s[:pos]+s[pos:].replace(needle,'',1)
def postcheck(s):
 needle='$this->requireCurrent($context);';pos=s.index(needle,s.index('$result = $mutation'))
 return s[:pos]+s[pos:].replace(needle,'',1)
cases=[
 ('claim-exclusion',runtime,lambda s:s.replace("AND (status = 'queued' OR (status = 'running' AND lease_until <= decision.at))", "AND status IN ('queued', 'running')"),'concurrent workers cannot'),
 ('stale-renewal',authority,remove_checks,'an expired living worker'),
 ('detached-write',authority,detach,'a database mutation delayed'),
 ('expired-completion',runtime,lambda s:s.replace('$this->authority->finish($context, function', '$this->database->transactional(function').replace("UPDATE jobs SET status = 'completed', claim_token = NULL, lease_until = NULL, error = NULL WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()", "UPDATE jobs SET status = 'completed', claim_token = NULL, lease_until = NULL, error = NULL WHERE id = ? AND claim_token = ?"),'an expired Attempt loses'),
 ('stale-failure',runtime,lambda s:s.replace('$this->authority->finish($context, function', '$this->database->transactional(function').replace("error = ? WHERE id = ? AND claim_token = ? AND lease_until > clock_timestamp()", "error = ? WHERE id = ? AND ?::text IS NOT NULL"),'a real stale handler failure'),
 ('renewal-takeover',authority,remove_checks,'concurrent renewal and takeover'),
 ('custody-reconciliation',host,lambda s:s.replace('if ($db->fetchOne(', 'if (false && $db->fetchOne(',1),'a replacement reconciles'),
 ('transaction-clock',authority,lambda s:s.replace('clock_timestamp()', 'transaction_timestamp()'),'a database mutation delayed'),
]
records=[]
def run(name,stage,filter):
 path=out/(name+'-'+stage+'.log')
 with path.open('w') as f:
  r=subprocess.run(['./bin/test.sh','tests/Acceptance/OperationsFencing','tests/Feature/AttemptAuthorityTest.php','--filter',filter,'--compact','--colors=never'],env={**os.environ,'STASHD_TEST_PROCESSES':'1'},stdout=f,stderr=subprocess.STDOUT)
 text=path.read_text();return r.returncode,text
for name,path,mutate,filter in cases:
 original=path.read_text();changed=mutate(original)
 assert changed!=original,name
 print(name,'baseline',flush=True)
 rc,log=run(name,'baseline',filter)
 if rc: raise RuntimeError(name+' baseline failed')
 try:
  path.write_text(changed)
  rc,log=run(name,'violation',filter)
  detected=rc!=0 and 'Failed asserting' in log and not any(x in log for x in ['timed out','Worker exited before','SQLSTATE','ParseError','cleanup failed'])
  records.append(dict(control=name,baseline='PASS',violation='DETECTED' if detected else 'INVALID',exit=rc))
 finally:path.write_text(original)
 rc,log=run(name,'restored',filter)
 records[-1]['restored']='PASS' if rc==0 else 'FAIL'
 (out/'negative-controls.json').write_text(json.dumps(records,indent=2)+'\n')
 print(records[-1],flush=True)
 if not detected or rc: raise RuntimeError(name+' control invalid')
