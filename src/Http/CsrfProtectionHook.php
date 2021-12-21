<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace Vpn\Portal\Http;

use Vpn\Portal\Http\Exception\HttpException;
use Vpn\Portal\Validator;

/**
 * Protect against *Cross Site Request Forgery* (CSRF) for "POST" requests.
 */
class CsrfProtectionHook extends AbstractHook implements BeforeHookInterface
{
    public function beforeAuth(Request $request): ?Response
    {
        if (!$request->isBrowser()) {
            // not a browser, no CSRF protected needed
            return null;
        }

        // ignore GET, HEAD, OPTIONS as they have no side-effects...
        if (\in_array($request->getRequestMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        if (null !== $originHeader = $request->optionalHeader('HTTP_ORIGIN')) {
            Validator::matchesOrigin($request->getOrigin(), $originHeader);

            return null;
        }
        if (null !== $referrerHeader = $request->optionalHeader('HTTP_REFERER')) {
            Validator::matchesOrigin($request->getOrigin(), $referrerHeader);

            return null;
        }

        throw new HttpException('CSRF protection failed, no HTTP_ORIGIN or HTTP_REFERER header', 400);
    }
}
