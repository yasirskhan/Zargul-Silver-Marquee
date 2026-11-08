import https from 'node:https';
import http from 'node:http';
import fs from 'node:fs';
const key = fs.readFileSync(process.env.TLS_KEY);
const cert = fs.readFileSync(process.env.TLS_CERT);
const target = { host: '127.0.0.1', port: Number(process.env.PHP_PORT || 8099) };
const server = https.createServer({ key, cert }, (req, res) => {
  const headers = { ...req.headers, host: 'lichall.hafzatech.com', 'x-forwarded-proto': 'https' };
  const upstream = http.request({ ...target, method: req.method, path: req.url, headers }, up => {
    res.writeHead(up.statusCode || 502, up.headers); up.pipe(res);
  });
  upstream.on('error', () => { res.writeHead(502); res.end('upstream error'); });
  req.pipe(upstream);
});
server.listen(Number(process.env.PROXY_PORT || 8443), '127.0.0.1', () => console.log('central browser proxy ready'));