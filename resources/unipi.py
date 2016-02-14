import websocket
import thread
import time
import sys
import json
import urllib

try:
	addr=sys.argv[1]
	unipi = 'ws://' + addr + '/ws'
except :
	print 'Il faut donner un unipi'
	exit(3)
	
try:
	jeedom=sys.argv[2]
except :
	print 'Il faut donner un jeedom'
	exit(3)

def on_message(ws, message):
    obj = json.loads(message)
    dev = obj['dev'] 
    circuit = obj['circuit'] 
    value = obj['value']
    appel = '{jeedom}&type=unipi&messagetype=saveValue&addr={addr}&id={dev}{circuit}&value={value}'.format(jeedom=jeedom, addr=addr, dev=dev, circuit=circuit, value=value)
    #print appel
    opener = urllib.FancyURLopener({})
    f = opener.open(appel)
    f.close()

def on_error(ws, error):
    print error

def on_close(ws):
    print "### closed ###"



if __name__ == "__main__":

    #websocket.enableTrace(True)
    ws = websocket.WebSocketApp(unipi,
                                on_message = on_message,
                                on_error = on_error,
                                on_close = on_close)

    ws.run_forever()
