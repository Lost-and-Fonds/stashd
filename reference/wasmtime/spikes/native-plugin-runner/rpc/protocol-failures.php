<?php

declare(strict_types=1);

require __DIR__.'/FrameCodec.php';

$stream = fopen('php://temp', 'w+b');
try {
    FrameCodec::write($stream, ['payload' => str_repeat('x', FrameCodec::MAX_FRAME_BYTES)]);
    throw new RuntimeException('oversized outgoing frame was accepted');
} catch (FrameProtocolError) {
    echo "M2 frame-size limit: PASS\n";
}
fclose($stream);
