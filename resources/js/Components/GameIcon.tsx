// Renders a game-type silhouette supplied by the server. The SVG markup and its
// viewBox come from the admin-managed game_types registry (DB 2), already
// sanitized server-side. Monochrome icons inherit the chip's ink color via
// fill="currentColor"; colored icons keep their own fills. Renders nothing when
// no icon is set or icons are globally disabled (the server omits the markup).
import React from 'react';

export default function GameIcon({
    svg,
    viewBox = '0 0 512 512',
    size = 16,
}: {
    svg?: string | null;
    viewBox?: string;
    size?: number;
}) {
    if (!svg) return null;

    return (
        <svg
            viewBox={viewBox}
            width={size}
            height={size}
            fill="currentColor"
            aria-hidden="true"
            style={{ flex: '0 0 auto', display: 'block' }}
            dangerouslySetInnerHTML={{ __html: svg }}
        />
    );
}
