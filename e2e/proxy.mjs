// Dentro do container de testes, "localhost:PORTA" é encaminhado para o Nginx,
// para que o navegador use a mesma origem configurada no Sanctum (cookies e CSRF).
import net from 'node:net'
const port = Number(process.env.APP_PORT ?? 8080)
const [host, target] = (process.env.E2E_TARGET ?? 'nginx:8080').split(':')
net.createServer((client) => {
  const upstream = net.connect(Number(target), host)
  client.pipe(upstream).pipe(client)
  const close = () => { client.destroy(); upstream.destroy() }
  client.on('error', close); upstream.on('error', close)
}).listen(port, '127.0.0.1', () => console.log(`proxy localhost:${port} -> ${host}:${target}`))
