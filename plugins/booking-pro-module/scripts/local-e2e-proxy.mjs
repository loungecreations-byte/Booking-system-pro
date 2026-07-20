import http from "node:http";

const listenHost = process.env.DDB_E2E_PROXY_HOST || "127.0.0.1";
const listenPort = Number.parseInt(process.env.DDB_E2E_PROXY_PORT || "80", 10);
const targetHost = process.env.DDB_E2E_TARGET_HOST || "127.0.0.1";
const targetPort = Number.parseInt(process.env.DDB_E2E_TARGET_PORT || "10010", 10);

if (![listenPort, targetPort].every((port) => Number.isInteger(port) && port > 0 && port <= 65535)) {
  throw new Error("DDB E2E proxy ports must be valid TCP ports.");
}

const server = http.createServer((request, response) => {
  const upstream = http.request(
    {
      host: targetHost,
      port: targetPort,
      method: request.method,
      path: request.url,
      headers: request.headers,
    },
    (upstreamResponse) => {
      response.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
      upstreamResponse.pipe(response);
    },
  );

  upstream.on("error", (error) => {
    if (!response.headersSent) {
      response.writeHead(502, { "content-type": "text/plain; charset=utf-8" });
    }
    response.end(`Local E2E upstream unavailable: ${error.message}`);
  });

  request.pipe(upstream);
});

server.on("error", (error) => {
  console.error(`Local E2E proxy failed: ${error.message}`);
  process.exitCode = 1;
});

server.listen(listenPort, listenHost, () => {
  console.log(`Local E2E proxy listening on http://${listenHost}:${listenPort} -> http://${targetHost}:${targetPort}`);
});

for (const signal of ["SIGINT", "SIGTERM"]) {
  process.on(signal, () => server.close(() => process.exit(0)));
}
