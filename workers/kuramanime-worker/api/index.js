import { handleRequest } from '../resolver.js';

export default async function handler(req, res) {
    return handleRequest(req, res);
}
