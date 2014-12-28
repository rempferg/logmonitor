#!/usr/bin/env python

from __future__ import print_function
import collections
import datetime
import lockfile
import MySQLdb
import operator
import os
import signal
import re
import threading
import time
import sys


loglevel = 0 #0=all
log_path = '/var/log/logmonitor.log'

pidfile = '/var/run/logmonitor.pid'

poll_sleep = 5
sync_sleep = 10
error_sleep = 30

#parameters chosen so that stationary priority becomes hits/24h
priority_decay_time = 300
priority_decay_factor = 0.996527778


def sigterm_handler(_signo, _stack_frame):
    sys.exit(0)

signal.signal(signal.SIGTERM, sigterm_handler)

with open(os.path.dirname(__file__) + '/.ht_dblogin', 'r') as fp:
    db_login = [l.rstrip('\n') for l in fp.readlines()]

logfile_fp = None

def log(message, priority=0):
    global logfile_fp, loglevel

    if priority < loglevel:
        return

    message = time.strftime('%Y-%m-%d %H:%M:%S ') + message

    if logfile_fp is sys.stderr or logfile_fp is sys.stdout:
        print(message, file=logfile_fp)
        return

    try:
        if logfile_fp is None:
            logfile_fp = open(log_path, 'a')
        elif not os.path.isfile(log_path):
            logfile_fp.close()
            logfile_fp = open(log_path, 'a')
        else:
            if os.fstat(logfile_fp.fileno()).st_ino != os.stat(log_path).st_ino or \
               os.fstat(logfile_fp.fileno()).st_dev != os.stat(log_path).st_dev:
                logfile_fp.close()
                logfile_fp = open(log_path, 'a' )
                print('Reopening %s after logrotation' % (log_path,), file=logfile_fp)
    
        print(message, file=logfile_fp)
        logfile_fp.flush()

    except Exception as e:
        print(message)
        print('%s [log] Error: %s' % (time.strftime('%Y-%m-%d %H:%M:%S'), str(e)))


class Logmonitor(threading.Thread):
    def __init__(self, id, db):
        threading.Thread.__init__(self)
        self.id = id
        self.path = None
        self.log = None
        self.rules = collections.OrderedDict()
        self.last_rule_change = 0
        self.update_data(db)
        self.update_rules(db)

        if os.path.isfile(self.path):
            self.log = open(self.path, 'r')

    def update_data(self, db):
        cur = db.cursor(MySQLdb.cursors.DictCursor)
        cur.execute('SELECT path FROM logfiles WHERE monitoring = 1 AND id = %s', (self.id,))

        if cur.rowcount == 0:
            log('[%d] Worker thread no longer needed' % (self.id,), priority=1)

            cur.execute('SELECT id FROM logfiles WHERE id = %s', (self.id,))
            if cur.rowcount == 0:
                cur.execute('DELETE FROM offenders WHERE logfile_id = %s', (self.id,))

            cur.close()
            self.id = None
            return

        data = cur.fetchone()
        cur.close()
        self.path = data['path']

    def update_rules(self, db):
        if self.id is None:
            return

        cur = db.cursor(MySQLdb.cursors.DictCursor)

        #remove no longer existing rules
        cur.execute('SELECT id FROM (%s) AS tmp WHERE id NOT IN (SELECT id FROM rules WHERE logfile_id = %s AND active != 0)' % (' UNION SELECT '.join(['SELECT NULL AS id'] + map(lambda x: str(x[1]['id']), self.rules.iteritems())), self.id))

        for rule in cur:
            if not rule['id'] is None:
                log('[%d] Deleting rule %d' % (self.id, rule['id']))
                del self.rules[rule['id']]

        #update changed rules and add new ones
        cur.execute('SELECT id, regex, priority, last_usage FROM rules WHERE active != 0 AND logfile_id = %s AND last_change > %s ORDER BY priority desc', (self.id, self.last_rule_change))

        for rule in cur:
            if rule['id'] in self.rules:
                log('[%d] Updating rule %d' % (self.id, rule['id']))
                self.rules[rule['id']] = cur.fetchone()
                self.rules[rule['id']]['regex_compiled'] = re.compile(rule['regex'])
            else:
                log('[%d] Creating rule %d' % (self.id, rule['id']))
                self.rules[rule['id']] = cur.fetchone()
                self.rules[rule['id']]['regex_compiled'] = re.compile(rule['regex'])
                self.rules[rule['id']]['dirty'] = 0

        self.rules = collections.OrderedDict(sorted(self.rules.iteritems(), key=lambda x:x[1]['priority'], reverse=True))

        #update last_rule_change
        cur.execute('SELECT last_change FROM rules WHERE active != 0 ORDER BY last_change DESC LIMIT 0,1')

        if cur.rowcount != 0:
            self.last_rule_change = cur.fetchone()['last_change']
        else:
            self.last_rule_change = 0

        cur.close()

    def flush_rules(self, db):
        cur = db.cursor(MySQLdb.cursors.DictCursor)

        for rule in self.rules.iteritems():
            if rule[1]['dirty'] != 0: 
                log('[%d] Flushing rule %d' % (self.id, rule[1]['id']))
                cur.execute('UPDATE rules SET priority = %s, last_usage = %s, last_change = last_change WHERE id = %s', (rule[1]['priority'], rule[1]['last_usage'], rule[1]['id']))
                rule[1]['dirty'] = 0
        
        cur.close()

    def downscale_priority(self):
        for rule in self.rules.iteritems():
            rule[1]['priority'] *= priority_decay_factor
            rule[1]['dirty'] = True

    def run(self):
        global db_login

        log('[%d] Monitoring %s' % (self.id,self.path), priority=1)

        reconnect = True

        while True:
            if self.id is None:
                try:
                    db_thread.close()
                except:
                    pass

                break

            if self.log is None:
                if os.path.isfile(self.path):
                    log('[%d] Opening logfile %s' % (self.id, self.path))
                    self.log = open(self.path, 'r')
                else:
                    log('[%d] Logfile %s doesn\'t exist' % (self.id, self.path))
                    time.sleep(poll_sleep)
                    continue

            line = self.log.readline()

            if len(line) == 0:
                if os.path.isfile(self.path):
                    if os.fstat(self.log.fileno()).st_ino != os.stat(self.path).st_ino or \
                       os.fstat(self.log.fileno()).st_dev != os.stat(self.path).st_dev:
                        log('[%d] Opening new logfile %s' % (self.id, self.path))
                        self.log.close()
                        self.log = open(self.path, 'r' )
                        continue
                else:
                    log('[%d] Logfile %s doesn\'t exist' % (self.id, self.path))

                db_thread.commit()
                time.sleep(poll_sleep)
            else:
                try:
                    if reconnect:
                        try:
                            db_thread.close()
                        except:
                            pass

                        db_thread = MySQLdb.connect(db_login[0], db_login[1], db_login[2], db_login[3])
                        reconnect = False

                    #match rules
                    match = False

                    for rule in self.rules.iteritems():
                        if rule[1]['regex_compiled'].match(line) is not None:
                            rule[1]['priority'] += 1.0
                            rule[1]['last_usage'] = datetime.datetime.now()
                            rule[1]['dirty'] = 1
                            #print('[%d] Rule %d: priority %f, last_usage %s, dirty %d' % (self.id, rule[1]['id'], rule[1]['priority'], str(rule[1]['last_usage']), rule[1]['dirty'])) 
                            match = True
                            break

                    if not match:
                        cur_thread = db_thread.cursor()
                        cur_thread.execute('INSERT INTO offenders(logfile_id, line) values(%s, "%s")', (self.id, line))
                        cur_thread.close()

                except Exception as e:
                    log('[%d] Error: %s' % (self.id, str(e)), priority=1)
                    reconnect = True
                    time.sleep(error_sleep)


if __name__ == '__main__':
    reconnect = True
    monitors = {}
    last_downscaling = time.time()

    while True:
        try:
            if reconnect:
                try:
                    db.close()
                except:
                    pass

                db = MySQLdb.connect(db_login[0], db_login[1], db_login[2], db_login[3])
                reconnect = False
                
            for k in monitors.keys():
                #periodically downscale priority
                if time.time() - last_downscaling > priority_decay_time:
                    monitors[k].downscale_priority()
                    last_downscaling = time.time()

                if monitors[k].isAlive():
                    monitors[k].update_data(db)
                    monitors[k].flush_rules(db)
                    monitors[k].update_rules(db)
                else:
                    log('[main] Removing worker thread %d' % (k,), priority=1)
                    del monitors[k]

            #create new monitors if needed
            cur = db.cursor(MySQLdb.cursors.DictCursor)

            cur.execute('SELECT id FROM logfiles WHERE monitoring = 1 AND id NOT IN(%s)' % (','.join(["''"] + map(str, monitors.keys())),))

            for logfile in cur:
                log('[main] Adding worker thread %d' % (logfile['id'],), priority=1)
                monitors[logfile['id']] = Logmonitor(logfile['id'], db)
                monitors[logfile['id']].daemon = True
                monitors[logfile['id']].start()
            
            db.commit()
            time.sleep(sync_sleep)

        except Exception as e:
            log('[main] Error: %s' % (str(e),), priority=1)
            reconnect = True
            time.sleep(error_sleep)

    db.close()
