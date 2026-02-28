/**
 * SoloBoard Terminal Server
 *
 * WebSocket server that spawns Claude Code CLI processes with PTY support.
 * Allows real-time interaction with Claude Code from the browser.
 */

const pty = require('node-pty');
const WebSocket = require('ws');
const path = require('path');

require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const PORT = process.env.TERMINAL_SERVER_PORT || 3001;
const PROJECT_PATH = process.env.TERMINAL_PROJECT_PATH || path.join(__dirname, '..');

// Store active sessions
const sessions = new Map();

const wss = new WebSocket.Server({ port: PORT });

console.log(`[terminal-server] Listening on ws://localhost:${PORT}`);
console.log(`[terminal-server] Project path: ${PROJECT_PATH}`);

wss.on('connection', (ws, req) => {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    const taskId = url.searchParams.get('task');
    const sessionPrompt = url.searchParams.get('prompt');
    const cols = parseInt(url.searchParams.get('cols')) || 120;
    const rows = parseInt(url.searchParams.get('rows')) || 30;

    console.log(`[terminal-server] New connection for task ${taskId}`);

    // Check if session already exists for this task
    if (sessions.has(taskId)) {
        const existingSession = sessions.get(taskId);
        console.log(`[terminal-server] Reconnecting to existing session for task ${taskId}`);

        // Attach new WebSocket to existing PTY
        existingSession.ws = ws;

        // Send reconnection message
        ws.send(JSON.stringify({
            type: 'system',
            data: '\r\n[Reconectado à sessão existente]\r\n'
        }));

        setupWebSocketHandlers(ws, existingSession.pty, taskId);
        return;
    }

    // Determine shell and args
    const shell = process.platform === 'win32' ? 'powershell.exe' : 'zsh';
    const shellArgs = process.platform === 'win32' ? [] : ['-l'];

    // Spawn PTY
    const ptyProcess = pty.spawn(shell, shellArgs, {
        name: 'xterm-256color',
        cols: cols,
        rows: rows,
        cwd: PROJECT_PATH,
        env: {
            ...process.env,
            TERM: 'xterm-256color',
            COLORTERM: 'truecolor',
        },
    });

    console.log(`[terminal-server] Spawned PTY process ${ptyProcess.pid} for task ${taskId}`);

    // Store session
    sessions.set(taskId, {
        pty: ptyProcess,
        ws: ws,
        taskId: taskId,
        startedAt: new Date(),
    });

    // Send initial message
    ws.send(JSON.stringify({
        type: 'system',
        data: `[SoloBoard Terminal - Task #${taskId}]\r\n`
    }));

    // Setup handlers
    setupWebSocketHandlers(ws, ptyProcess, taskId);

    // After shell is ready, start claude if prompt provided
    if (sessionPrompt) {
        setTimeout(() => {
            // Start claude code with the prompt
            const claudeCommand = `claude "${sessionPrompt.replace(/"/g, '\\"')}"\n`;
            ptyProcess.write(claudeCommand);
        }, 500);
    }
});

function setupWebSocketHandlers(ws, ptyProcess, taskId) {
    // PTY output -> WebSocket
    const dataHandler = (data) => {
        if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({ type: 'output', data: data }));
        }
    };
    ptyProcess.onData(dataHandler);

    // PTY exit
    const exitHandler = (exitCode, signal) => {
        console.log(`[terminal-server] PTY exited for task ${taskId} (code: ${exitCode}, signal: ${signal})`);
        if (ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify({
                type: 'exit',
                code: exitCode,
                signal: signal
            }));
        }
        sessions.delete(taskId);
    };
    ptyProcess.onExit(exitHandler);

    // WebSocket messages -> PTY
    ws.on('message', (message) => {
        try {
            const msg = JSON.parse(message.toString());

            switch (msg.type) {
                case 'input':
                    ptyProcess.write(msg.data);
                    break;

                case 'resize':
                    if (msg.cols && msg.rows) {
                        ptyProcess.resize(msg.cols, msg.rows);
                    }
                    break;

                case 'ping':
                    ws.send(JSON.stringify({ type: 'pong' }));
                    break;

                default:
                    console.log(`[terminal-server] Unknown message type: ${msg.type}`);
            }
        } catch (err) {
            console.error(`[terminal-server] Error parsing message:`, err);
        }
    });

    // WebSocket close
    ws.on('close', () => {
        console.log(`[terminal-server] WebSocket closed for task ${taskId}`);
        // Don't kill PTY immediately - allow reconnection
        // PTY will be killed when task is marked as done
    });

    // WebSocket error
    ws.on('error', (err) => {
        console.error(`[terminal-server] WebSocket error for task ${taskId}:`, err);
    });
}

// API to kill session (called when task is done)
wss.on('connection', (ws, req) => {
    if (req.url.startsWith('/kill')) {
        const url = new URL(req.url, `http://localhost:${PORT}`);
        const taskId = url.searchParams.get('task');

        if (sessions.has(taskId)) {
            const session = sessions.get(taskId);
            session.pty.kill();
            sessions.delete(taskId);
            ws.send(JSON.stringify({ type: 'killed', taskId: taskId }));
        }
        ws.close();
    }
});

// Graceful shutdown
process.on('SIGINT', () => {
    console.log('[terminal-server] Shutting down...');

    // Kill all PTY processes
    for (const [taskId, session] of sessions) {
        console.log(`[terminal-server] Killing session for task ${taskId}`);
        session.pty.kill();
    }

    wss.close(() => {
        console.log('[terminal-server] Server closed');
        process.exit(0);
    });
});

// Keep track of active sessions
setInterval(() => {
    if (sessions.size > 0) {
        console.log(`[terminal-server] Active sessions: ${sessions.size}`);
        for (const [taskId, session] of sessions) {
            const duration = Math.round((Date.now() - session.startedAt.getTime()) / 1000 / 60);
            console.log(`  - Task #${taskId}: ${duration} minutes`);
        }
    }
}, 60000); // Log every minute
